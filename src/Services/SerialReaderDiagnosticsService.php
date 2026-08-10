<?php

declare(strict_types=1);

namespace OTGH\AccessControl\SerialWiegandAdapter\Services;

use App\Models\Access\Event;
use App\Models\Hardware\Reader;
use App\Services\AccessControl\AccessControlSettingsRepository;
use App\Services\AccessControl\SerialReaderDiagnosticsServiceInterface;

class SerialReaderDiagnosticsService implements SerialReaderDiagnosticsServiceInterface
{
    public function __construct(private readonly AccessControlSettingsRepository $settings) {}

    /**
     * @return array{
     *     generated_at:string,
     *     readers_total:int,
     *     running_monitors:int,
     *     readable_devices:int,
     *     command_processes:int,
     *     readers:array<int,array<string,mixed>>
     * }
     */
    public function buildPayload(): array
    {
        [$supervisorOutput, $supervisorProbeState] = $this->resolveSupervisorStatusOutput();
        $readers = Reader::query()
            ->with(['area'])
            ->orderBy('name')
            ->orderBy('identifier')
            ->get();

        $rows = [];
        $runningMonitors = 0;
        $readableDevices = 0;

        foreach ($readers as $reader) {
            $inputFormat = strtolower((string) data_get($reader->config, 'general.input_format', 'wiegand'));
            if ($inputFormat !== 'wiegand') {
                continue;
            }

            $devicePath = (string) data_get($reader->config, 'wiegand.device', '/dev/'.$reader->identifier);
            $processMatchesOutput = $this->runShellCommand(sprintf('pgrep -fa %s 2>/dev/null', escapeshellarg('artisan app:monitor-serial-reader '.$reader->identifier)));
            $processMatches = $this->countLines($processMatchesOutput);
            $processRunning = $processMatches > 0;

            if ($processRunning) {
                $runningMonitors++;
            }

            $deviceExists = $devicePath !== '' && file_exists($devicePath);
            $deviceReadable = $deviceExists && is_readable($devicePath);

            if ($deviceReadable) {
                $readableDevices++;
            }

            $latestEvent = Event::query()
                ->where('origin_type', 'reader')
                ->where('origin_id', $reader->id)
                ->latest('id')
                ->first();

            $supervisorProgram = 'access-control-serial-'.$reader->identifier;
            $supervisorLines = $this->findProgramLines((string) $supervisorOutput, $supervisorProgram);
            $supervisorStatus = $this->normalizeSupervisorStatus($supervisorLines, $supervisorProbeState);

            $rows[] = [
                'id' => $reader->id,
                'name' => $reader->name,
                'identifier' => $reader->identifier,
                'area_name' => $reader->area?->name,
                'device' => $devicePath,
                'baud_rate' => (int) data_get($reader->config, 'wiegand.baud_rate', $this->settings->getInt('wiegand.default_baud_rate', 9600)),
                'timeout' => (float) data_get($reader->config, 'wiegand.timeout', (float) $this->settings->get('wiegand.default_timeout_seconds', 1.0)),
                'duplicate_window' => (float) data_get($reader->config, 'wiegand.duplicate_window', (float) $this->settings->get('wiegand.default_duplicate_window_seconds', 2.0)),
                'doorbell_duplicate_window' => (float) data_get($reader->config, 'wiegand.doorbell_duplicate_window', (float) $this->settings->get('wiegand.default_doorbell_duplicate_window_seconds', 2.0)),
                'keypad_timeout' => (float) data_get($reader->config, 'wiegand.keypad_timeout', (float) $this->settings->get('wiegand.default_keypad_timeout_seconds', 3.0)),
                'card_min_value' => (int) data_get($reader->config, 'wiegand.card_min_value', $this->settings->getInt('wiegand.default_card_min_value', 15)),
                'doorbell_value' => (int) data_get($reader->config, 'wiegand.doorbell_value', $this->settings->getInt('wiegand.default_doorbell_value', 11)),
                'reader_mode' => (string) data_get($reader->config, 'general.reader_mode', 'card_only'),
                'process_matches' => $processMatches,
                'process_running' => $processRunning,
                'device_exists' => $deviceExists,
                'device_readable' => $deviceReadable,
                'supervisor_program' => $supervisorProgram,
                'supervisor_status' => $supervisorStatus,
                'supervisor_details' => $this->buildSupervisorDetails($supervisorLines, $supervisorProbeState),
                'latest_event_status' => $latestEvent?->status_label,
                'latest_event_reason' => $latestEvent?->reason,
                'latest_event_at' => $latestEvent?->created_at?->toIso8601String(),
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'readers_total' => count($rows),
            'running_monitors' => $runningMonitors,
            'readable_devices' => $readableDevices,
            'command_processes' => $this->countLines($this->runShellCommand('pgrep -fa "artisan app:monitor-serial-reader" 2>/dev/null')),
            'readers' => $rows,
        ];
    }

    /**
     * @return list<string>
     */
    private function findProgramLines(string $output, string $program): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];

        return array_values(array_filter($lines, static function (string $line) use ($program): bool {
            return str_starts_with(trim($line), $program);
        }));
    }

    private function normalizeSupervisorStatus(array $lines, string $probeState = 'ok'): string
    {
        if ($lines === []) {
            return match ($probeState) {
                'permission_gated' => 'permission-gated',
                'command_missing' => 'command-missing',
                'unavailable' => 'unavailable',
                default => 'unknown',
            };
        }

        foreach ($lines as $line) {
            if (stripos($line, ' RUNNING ') !== false || str_ends_with(trim($line), ' RUNNING')) {
                return 'running';
            }

            if (stripos($line, ' BACKOFF') !== false) {
                return 'backoff';
            }

            if (stripos($line, ' STOPPED') !== false) {
                return 'stopped';
            }

            if (stripos($line, ' FATAL') !== false) {
                return 'fatal';
            }
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $lines
     */
    private function buildSupervisorDetails(array $lines, string $probeState): string
    {
        if ($lines !== []) {
            return implode(' | ', $lines);
        }

        return match ($probeState) {
            'permission_gated' => 'supervisorctl is permission-gated for this process context.',
            'command_missing' => 'supervisorctl command not found in this process context.',
            'unavailable' => 'Unable to execute supervisorctl status.',
            default => 'Program not found in supervisor status output.',
        };
    }

    /**
     * @return array{0:string|null,1:string}
     */
    private function resolveSupervisorStatusOutput(): array
    {
        $commands = [
            'supervisorctl status 2>&1',
            'sudo -n supervisorctl status 2>&1',
        ];

        $probeState = 'unavailable';

        foreach ($commands as $command) {
            $output = $this->runShellCommand($command);

            if (! is_string($output) || trim($output) === '') {
                continue;
            }

            $trimmed = trim($output);
            $lowered = strtolower($trimmed);

            if (str_contains($lowered, 'command not found')) {
                $probeState = 'command_missing';

                continue;
            }

            if (
                str_contains($lowered, 'permission denied')
                || str_contains($lowered, 'a password is required')
                || str_contains($lowered, 'not in the sudoers file')
            ) {
                $probeState = 'permission_gated';

                continue;
            }

            return [$trimmed, 'ok'];
        }

        return [null, $probeState];
    }

    private function countLines(?string $output): int
    {
        if (! is_string($output) || trim($output) === '') {
            return 0;
        }

        return count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($output)) ?: [])));
    }

    private function runShellCommand(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec($command);

        return is_string($output) ? trim($output) : null;
    }
}
