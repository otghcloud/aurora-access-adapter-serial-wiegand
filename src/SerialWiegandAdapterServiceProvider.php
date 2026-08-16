<?php

declare(strict_types=1);

namespace OTGH\AccessControl\SerialWiegandAdapter;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use OTGH\AccessControl\Core\Services\AccessControl\AccessControlConfigurationRegistry;
use OTGH\AccessControl\Core\Services\AccessControl\DiagnosticsNavigationRegistry;
use OTGH\AccessControl\Core\Services\AccessControl\SerialReaderDiagnosticsServiceInterface;
use OTGH\AccessControl\Core\Services\Supervisor\SupervisorProgramRegistry;
use OTGH\AccessControl\SerialWiegandAdapter\AccessControl\SerialReaderInputProcessor;
use OTGH\AccessControl\SerialWiegandAdapter\Console\Commands\MonitorSerialReader;
use OTGH\AccessControl\SerialWiegandAdapter\Services\SerialReaderDiagnosticsService;

class SerialWiegandAdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SerialReaderInputProcessor::class);
        $this->app->singleton(SerialReaderDiagnosticsService::class);
        $this->app->singleton(SerialReaderDiagnosticsServiceInterface::class, function () {
            return $this->app->make(SerialReaderDiagnosticsService::class);
        });

        $this->app->afterResolving(AccessControlConfigurationRegistry::class, function (AccessControlConfigurationRegistry $registry): void {
            $registry->registerField(
                key: 'wiegand.default_baud_rate',
                label: 'Default Baud Rate',
                type: 'integer',
                description: 'Fallback serial baud rate when reader config omits serial.baud_rate.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 9600,
            );

            $registry->registerField(
                key: 'wiegand.default_timeout_seconds',
                label: 'Default Serial Timeout (Seconds)',
                type: 'float',
                description: 'Fallback serial timeout used by serial monitor and diagnostics.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 1.0,
            );

            $registry->registerField(
                key: 'wiegand.default_duplicate_window_seconds',
                label: 'Default Duplicate Card Window (Seconds)',
                type: 'float',
                description: 'Fallback duplicate card suppression window.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 2.0,
            );

            $registry->registerField(
                key: 'wiegand.default_doorbell_duplicate_window_seconds',
                label: 'Default Doorbell Duplicate Window (Seconds)',
                type: 'float',
                description: 'Fallback duplicate doorbell suppression window.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 2.0,
            );

            $registry->registerField(
                key: 'wiegand.default_keypad_timeout_seconds',
                label: 'Default Keypad Timeout (Seconds)',
                type: 'float',
                description: 'Fallback keypad buffer timeout before emitting a code.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 3.0,
            );

            $registry->registerField(
                key: 'wiegand.default_card_min_value',
                label: 'Default Card Minimum Value',
                type: 'integer',
                description: 'Values greater than or equal are treated as card reads.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 15,
            );

            $registry->registerField(
                key: 'wiegand.default_doorbell_value',
                label: 'Default Doorbell Value',
                type: 'integer',
                description: 'Serial value interpreted as a doorbell press.',
                section: 'serial',
                sectionLabel: 'Serial Wiegand Adapter',
                package: 'otghcloud/aurora-access-adapter-serial-wiegand',
                default: 11,
            );
        });

        $this->app->afterResolving(DiagnosticsNavigationRegistry::class, function (DiagnosticsNavigationRegistry $registry): void {
            $registry->register('admin.serial-devices', 'Serial Devices', 20);
        });

        $this->app->afterResolving(SupervisorProgramRegistry::class, function (SupervisorProgramRegistry $registry): void {
            $registry->register(function (string $phpBinary, string $workingDir): array {
                if (! Schema::hasTable('readers')) {
                    return [];
                }

                $sections = [];

                foreach (Reader::query()->orderBy('identifier', 'asc')->get(['identifier', 'config']) as $reader) {
                    $inputFormat = strtolower((string) data_get($reader->config, 'general.input_format', 'wiegand'));
                    if ($inputFormat !== 'wiegand') {
                        continue;
                    }

                    $configuredDevice = trim((string) data_get($reader->config, 'wiegand.device', ''));
                    $identifier = trim((string) $reader->identifier);

                    $isLikelySerial = (bool) preg_match('/^(tty|cu\.|rfcomm|serial|usb)/i', $identifier);
                    if ($configuredDevice === '' && ! $isLikelySerial) {
                        continue;
                    }

                    if ($identifier === '') {
                        continue;
                    }

                    $sanitized = Str::of($identifier)
                        ->replaceMatches('/[^A-Za-z0-9_-]+/', '-')
                        ->trim('-')
                        ->toString();
                    $sanitized = $sanitized !== '' ? $sanitized : 'reader';
                    $readerArg = escapeshellarg($identifier);

                    $sections[] = <<<CONF
[program:access-control-serial-{$sanitized}]
command={$phpBinary} {$workingDir}/artisan app:monitor-serial-reader {$readerArg}
autostart=true
autorestart=true
startsecs=2
startretries=10
user=www-data
directory={$workingDir}
redirect_stderr=true
stdout_logfile={$workingDir}/storage/logs/supervisor-serial-{$sanitized}.log
stopasgroup=true
killasgroup=true
stopwaitsecs=30
CONF;
                }

                return $sections;
            });
        });

    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'serial-wiegand-adapter');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MonitorSerialReader::class,
            ]);
        }
    }
}
