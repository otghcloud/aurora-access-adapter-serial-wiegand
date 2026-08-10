<?php

use App\Models\Access\Area;
use App\Models\Hardware\Reader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores serial reader configuration from the admin reader form', function () {
    $admin = User::factory()->create();

    $area = Area::create([
        'name' => 'Reception',
        'identifier' => 'reception',
        'metadata' => [],
    ]);

    $response = $this->actingAs($admin)->post(route('admin.access-readers.store'), [
        'name' => 'Reception Reader',
        'identifier' => 'ttyUSB7',
        'area_id' => $area->id,
        'general_autolock_enabled' => '1',
        'general_autolock_duration' => '15',
        'general_feedback_state_duration' => '5',
        'general_reader_mode' => 'keypad',
        'wiegand_device' => '/dev/ttyUSB7',
        'wiegand_baud_rate' => '19200',
        'wiegand_timeout' => '1.5',
        'wiegand_duplicate_window' => '2.5',
        'wiegand_doorbell_duplicate_window' => '3',
        'wiegand_keypad_timeout' => '4.5',
        'wiegand_card_min_value' => '20',
        'wiegand_doorbell_value' => '12',
        'metadata_reader_model' => '',
        'metadata_reader_type' => '',
        'metadata_lock_model' => '',
        'metadata_lock_type' => '',
    ]);

    $response->assertRedirect(route('admin.access-readers.index'));

    $reader = Reader::query()->where('identifier', 'ttyUSB7')->firstOrFail();

    expect(data_get($reader->config, 'wiegand.device'))->toBe('/dev/ttyUSB7');
    expect(data_get($reader->config, 'wiegand.baud_rate'))->toBe(19200);
    expect(data_get($reader->config, 'wiegand.timeout'))->toBe(1.5);
    expect(data_get($reader->config, 'wiegand.duplicate_window'))->toBe(2.5);
    expect((float) data_get($reader->config, 'wiegand.doorbell_duplicate_window'))->toBe(3.0);
    expect(data_get($reader->config, 'wiegand.keypad_timeout'))->toBe(4.5);
    expect(data_get($reader->config, 'wiegand.card_min_value'))->toBe(20);
    expect(data_get($reader->config, 'wiegand.doorbell_value'))->toBe(12);
});

it('shows serial configuration on the reader detail page', function () {
    $admin = User::factory()->create();

    $reader = Reader::create([
        'name' => 'Office Reader',
        'identifier' => 'ttyUSB2',
        'config' => [
            'general' => [
                'autolock_enabled' => true,
                'autolock_duration' => 15,
                'feedback_state_duration' => 5,
                'reader_mode' => 'card_only',
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

    $response = $this->actingAs($admin)->get(route('admin.access-readers.show', $reader));

    $response->assertOk();
    $response->assertSee('Serial Wiegand');
    $response->assertSee('/dev/ttyUSB2');
    $response->assertSee('9600');
    $response->assertSee('11');
});
