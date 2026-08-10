<?php

declare(strict_types=1);

namespace OTGH\AccessControl\SerialWiegandAdapter\Console\Commands;

use App\Models\Hardware\Reader;
use App\Services\AccessControl\AccessControlSettingsRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\SerialWiegandAdapter\AccessControl\SerialReaderInputProcessor;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('app:monitor-serial-reader {reader : Reader identifier} {--device= : Override serial device path} {--baud= : Override baud rate} {--timeout= : Override serial timeout in seconds} {--poll-us=100000 : Sleep between empty polls in microseconds}')]
#[Description('Monitor a serial-connected access reader and dispatch access events directly inside Laravel')]
class MonitorSerialReader extends Command
{
    public function __construct(private readonly AccessControlSettingsRepository $settings)
    {
        parent::__construct();
    }

    public function handle(SerialReaderInputProcessor $processor): int
    {
        $readerIdentifier = (string) $this->argument('reader');
        $reader = Reader::query()
            ->where('identifier', $readerIdentifier)
            ->first();

        if (! $reader) {
            $this->error("Reader [{$readerIdentifier}] was not found.");

            return self::FAILURE;
        }

        $device = $this->resolveDevice($reader);
        $baudRate = $this->resolveBaudRate($reader);
        $timeout = $this->resolveTimeout($reader);
        $pollIntervalUs = max(1000, (int) $this->option('poll-us'));

        if (! file_exists($device) || ! is_readable($device)) {
            $this->error("Serial device [{$device}] is not readable.");

            return self::FAILURE;
        }

        try {
            $this->configureDevice($device, $baudRate, $timeout);
            $stream = @fopen($device, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException("Failed to open serial device [{$device}].");
            }

            stream_set_blocking($stream, false);

            $this->info("Monitoring serial reader [{$reader->identifier}] on [{$device}] @ {$baudRate} baud.");
            Log::info('Serial reader monitor started.', [
                'reader_identifier' => $reader->identifier,
                'device' => $device,
                'baud_rate' => $baudRate,
                'timeout' => $timeout,
            ]);

            while (true) {
                $line = fgets($stream);

                if ($line !== false) {
                    $processor->processLine($reader, $line);
                }

                $processor->flushTimedOutKeypadBuffer($reader);

                if ($line === false) {
                    usleep($pollIntervalUs);
                }
            }
        } catch (Throwable $e) {
            Log::error('Serial reader monitor stopped unexpectedly.', [
                'reader_identifier' => $reader->identifier,
                'device' => $device,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function resolveDevice(Reader $reader): string
    {
        $configuredDevice = $this->option('device');

        if (is_string($configuredDevice) && trim($configuredDevice) !== '') {
            return trim($configuredDevice);
        }

        $readerConfigDevice = data_get($reader->config, 'wiegand.device');

        if (is_string($readerConfigDevice) && trim($readerConfigDevice) !== '') {
            return trim($readerConfigDevice);
        }

        return '/dev/'.trim($reader->identifier);
    }

    private function resolveBaudRate(Reader $reader): int
    {
        $configuredBaudRate = $this->option('baud');

        if (is_numeric($configuredBaudRate)) {
            return max(1, (int) $configuredBaudRate);
        }

        $default = $this->settings->getInt('wiegand.default_baud_rate', 9600);

        return max(1, (int) data_get($reader->config, 'wiegand.baud_rate', $default));
    }

    private function resolveTimeout(Reader $reader): float
    {
        $configuredTimeout = $this->option('timeout');

        if (is_numeric($configuredTimeout)) {
            return max(0.1, (float) $configuredTimeout);
        }

        $default = max(0.1, (float) $this->settings->get('wiegand.default_timeout_seconds', 1.0));

        return max(0.1, (float) data_get($reader->config, 'wiegand.timeout', $default));
    }

    private function configureDevice(string $device, int $baudRate, float $timeout): void
    {
        $timeoutDeciseconds = max(0, min(255, (int) round($timeout * 10)));

        $process = new Process([
            'stty',
            '-F',
            $device,
            (string) $baudRate,
            'cs8',
            '-cstopb',
            '-parenb',
            '-icanon',
            '-echo',
            'min',
            '0',
            'time',
            (string) $timeoutDeciseconds,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Failed to configure serial device with stty.');
        }
    }
}
