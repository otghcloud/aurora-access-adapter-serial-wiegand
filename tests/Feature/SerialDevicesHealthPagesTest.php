<?php

use App\Models\Hardware\Reader;
use App\Models\User;
use App\Services\AccessControl\SerialReaderDiagnosticsServiceInterface;
use App\Services\AccessControlHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows serial device summary on the health overview page', function () {
    $admin = User::factory()->create();

    Reader::create([
        'name' => 'Office Reader',
        'identifier' => 'ttyUSB2',
        'config' => [
            'general' => [
                'autolock_enabled' => true,
                'autolock_duration' => 15,
                'feedback_state_duration' => 5,
                'reader_mode' => 'card_only',
                'input_format' => 'wiegand',
            ],
            'wiegand' => [
                'device' => '/dev/ttyUSB2',
                'baud_rate' => 9600,
                'timeout' => 1,
                'duplicate_window' => 2,
                'doorbell_duplicate_window' => 2,
                'keypad_timeout' => 3,
                'card_min_value' => 15,
                'doorbell_value' => 11,
            ],
            'edgelink' => [
                'tags' => ['lock_power' => null, 'feedback_state' => null],
                'signal_reversed' => ['lock_power' => false, 'feedback_state' => false],
            ],
        ],
        'metadata' => [],
    ]);

    $health = Mockery::mock(AccessControlHealthService::class);
    $health->shouldReceive('generate')->once()->andReturn([
        'ok' => true,
        'generated_at' => now()->toIso8601String(),
        'queue_connection' => 'redis',
        'queue_name' => 'default',
        'redis_connection' => 'default',
        'critical_failures' => 0,
        'warnings' => 0,
        'checks' => [],
        'mqtt_sync' => null,
    ]);
    app()->instance(AccessControlHealthService::class, $health);

    $serialDiagnostics = Mockery::mock(SerialReaderDiagnosticsServiceInterface::class);
    $serialDiagnostics->shouldReceive('buildPayload')->once()->andReturn([
        'generated_at' => now()->toIso8601String(),
        'readers_total' => 1,
        'running_monitors' => 1,
        'readable_devices' => 1,
        'command_processes' => 1,
        'readers' => [
            [
                'id' => 1,
                'name' => 'Office Reader',
                'identifier' => 'ttyUSB2',
                'area_name' => null,
                'device' => '/dev/ttyUSB2',
                'baud_rate' => 9600,
                'timeout' => 1.0,
                'duplicate_window' => 2.0,
                'doorbell_duplicate_window' => 2.0,
                'keypad_timeout' => 3.0,
                'card_min_value' => 15,
                'doorbell_value' => 11,
                'reader_mode' => 'card_only',
                'process_matches' => 1,
                'process_running' => true,
                'device_exists' => true,
                'device_readable' => true,
                'supervisor_program' => 'access-control-serial-ttyUSB2',
                'supervisor_status' => 'running',
                'supervisor_details' => 'RUNNING',
                'latest_event_status' => 'success',
                'latest_event_reason' => null,
                'latest_event_at' => now()->toIso8601String(),
            ],
        ],
    ]);
    app()->instance(SerialReaderDiagnosticsServiceInterface::class, $serialDiagnostics);

    $response = $this->actingAs($admin)->get(route('admin.health'));

    $response->assertOk();
    $response->assertSee('Serial Devices');
    $response->assertSee('Configured Readers');
    $response->assertSee('Open Serial Devices');
    $response->assertSee('ttyUSB2');
});

it('renders the dedicated serial devices page', function () {
    $admin = User::factory()->create();

    $serialDiagnostics = Mockery::mock(SerialReaderDiagnosticsServiceInterface::class);
    $serialDiagnostics->shouldReceive('buildPayload')->once()->andReturn([
        'generated_at' => now()->toIso8601String(),
        'readers_total' => 1,
        'running_monitors' => 1,
        'readable_devices' => 1,
        'command_processes' => 1,
        'readers' => [
            [
                'id' => 1,
                'name' => 'Office Reader',
                'identifier' => 'ttyUSB2',
                'area_name' => 'Front Office',
                'device' => '/dev/ttyUSB2',
                'baud_rate' => 9600,
                'timeout' => 1.0,
                'duplicate_window' => 2.0,
                'doorbell_duplicate_window' => 2.0,
                'keypad_timeout' => 3.0,
                'card_min_value' => 15,
                'doorbell_value' => 11,
                'reader_mode' => 'keypad',
                'process_matches' => 1,
                'process_running' => true,
                'device_exists' => true,
                'device_readable' => true,
                'supervisor_program' => 'access-control-serial-ttyUSB2',
                'supervisor_status' => 'running',
                'supervisor_details' => 'RUNNING',
                'latest_event_status' => 'doorbell_pressed',
                'latest_event_reason' => 'Doorbell pressed',
                'latest_event_at' => now()->toIso8601String(),
            ],
        ],
    ]);
    app()->instance(SerialReaderDiagnosticsServiceInterface::class, $serialDiagnostics);

    $response = $this->actingAs($admin)->get(route('admin.serial-devices'));

    $response->assertOk();
    $response->assertSee('Serial Devices');
    $response->assertSee('Office Reader');
    $response->assertSee('Front Office');
    $response->assertSee('/dev/ttyUSB2');
    $response->assertSee('doorbell_pressed');
});
