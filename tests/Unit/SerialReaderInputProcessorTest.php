<?php

use App\Enums\AccessControl\AccessEventStatus;
use App\Jobs\ProcessReaderEvent;
use App\Jobs\PulseReaderFeedbackState;
use App\Models\Access\Card;
use App\Models\Access\Individual;
use App\Models\Hardware\Reader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OTGH\AccessControl\SerialWiegandAdapter\AccessControl\SerialReaderInputProcessor;

uses(RefreshDatabase::class);

it('dispatches a card read when a serial hex line contains a card value', function () {
    Queue::fake();

    $accessUser = Individual::create(['name' => 'Serial Card User']);
    $reader = Reader::create([
        'name' => 'Serial Reader',
        'identifier' => 'ttyUSB2',
        'config' => [],
        'metadata' => null,
    ]);
    $card = Card::create([
        'user_id' => $accessUser->id,
        'card_number' => '100',
        'description' => 'Serial card',
        'active' => true,
    ]);

    $processor = app(SerialReaderInputProcessor::class);
    $processor->processLine($reader, '64', 1000.0, '127.0.0.1');

    $this->assertDatabaseHas('events', [
        'card_number' => $card->card_number,
        'origin_type' => 'reader',
        'origin_id' => $reader->id,
        'status' => AccessEventStatus::SUCCESS->value,
    ]);

    Queue::assertPushed(ProcessReaderEvent::class, 1);
    Queue::assertPushed(PulseReaderFeedbackState::class, 1);
});

it('suppresses duplicate card reads inside the duplicate window', function () {
    Queue::fake();

    $accessUser = Individual::create(['name' => 'Duplicate User']);
    $reader = Reader::create([
        'name' => 'Duplicate Reader',
        'identifier' => 'ttyUSB3',
        'config' => [],
        'metadata' => null,
    ]);
    Card::create([
        'user_id' => $accessUser->id,
        'card_number' => '100',
        'description' => 'Duplicate card',
        'active' => true,
    ]);

    $processor = app(SerialReaderInputProcessor::class);
    $processor->processLine($reader, '64', 1000.0, '127.0.0.1');
    $processor->processLine($reader, '64', 1001.0, '127.0.0.1');

    expect($reader->events()->count())->toBe(1);
    Queue::assertPushed(ProcessReaderEvent::class, 1);
});

it('buffers keypad digits and flushes them on timeout', function () {
    Queue::fake();

    $accessUser = Individual::create(['name' => 'Keypad User']);
    $reader = Reader::create([
        'name' => 'Keypad Reader',
        'identifier' => 'ttyUSB4',
        'config' => [
            'wiegand' => [
                'keypad_timeout' => 3.0,
            ],
        ],
        'metadata' => null,
    ]);
    Card::create([
        'user_id' => $accessUser->id,
        'card_number' => '12',
        'description' => 'Keypad code',
        'active' => true,
    ]);

    $processor = app(SerialReaderInputProcessor::class);
    $processor->processLine($reader, '1', 1000.0, '127.0.0.1');
    $processor->processLine($reader, '2', 1001.0, '127.0.0.1');
    $processor->flushTimedOutKeypadBuffer($reader, 1004.1, '127.0.0.1');

    $this->assertDatabaseHas('events', [
        'card_number' => '12',
        'origin_type' => 'reader',
        'origin_id' => $reader->id,
        'status' => AccessEventStatus::SUCCESS->value,
    ]);

    Queue::assertPushed(ProcessReaderEvent::class, 1);
});

it('records a doorbell press for the configured doorbell value', function () {
    Queue::fake();

    $reader = Reader::create([
        'name' => 'Doorbell Reader',
        'identifier' => 'ttyUSB5',
        'config' => [],
        'metadata' => null,
    ]);

    $processor = app(SerialReaderInputProcessor::class);
    $processor->processLine($reader, 'b', 1000.0, '127.0.0.1');

    $this->assertDatabaseHas('events', [
        'origin_type' => 'reader',
        'origin_id' => $reader->id,
        'status' => AccessEventStatus::DOORBELL_PRESSED->value,
    ]);

});
