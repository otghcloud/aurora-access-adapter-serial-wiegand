<?php

declare(strict_types=1);

namespace OTGH\AccessControl\SerialWiegandAdapter\AccessControl;

use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\Core\Models\Hardware\Reader;
use OTGH\AccessControl\Core\Services\AccessControl\AccessControlSettingsRepository;
use OTGH\AccessControl\Core\Services\AccessControl\HandleAccessRequest;

class SerialReaderInputProcessor
{
    /** @var array<string,float> */
    private array $lastSeenByCardAndReader = [];

    /** @var array<string,list<string>> */
    private array $keypadBufferByReader = [];

    /** @var array<string,float> */
    private array $keypadLastPressByReader = [];

    /** @var array<string,float> */
    private array $doorbellLastSeenByReader = [];

    public function __construct(
        private readonly HandleAccessRequest $handleAccessRequest,
        private readonly AccessControlSettingsRepository $settings,
    ) {}

    public function processLine(Reader $reader, string $raw, ?float $now = null, ?string $ipAddress = null): void
    {
        $now ??= microtime(true);
        $raw = trim($raw);

        if ($raw === '') {
            return;
        }

        if (! ctype_xdigit($raw)) {
            Log::warning('Serial reader line ignored because it is not valid hexadecimal.', [
                'reader_identifier' => $reader->identifier,
                'raw' => $raw,
            ]);

            return;
        }

        $value = (int) hexdec($raw);

        if ($value === $this->doorbellValue($reader)) {
            $this->flushKeypadBuffer($reader, $now, $ipAddress);

            if ($this->isDuplicateDoorbell($reader, $now)) {
                Log::info('Ignored duplicate doorbell press.', ['reader_identifier' => $reader->identifier]);

                return;
            }

            $this->handleAccessRequest->recordDoorbellPress($reader->identifier, $ipAddress);

            return;
        }

        if ($value >= $this->cardMinValue($reader)) {
            $this->flushKeypadBuffer($reader, $now, $ipAddress);

            if ($this->isDuplicateCard($reader, (string) $value, $now)) {
                Log::info('Ignored duplicate card read.', [
                    'reader_identifier' => $reader->identifier,
                    'card_number' => (string) $value,
                ]);

                return;
            }

            $this->handleAccessRequest->validateCard((string) $value, $reader->identifier, $ipAddress);

            return;
        }

        if ($value === 10) {
            Log::debug('Ignored serial keypad settings key.', ['reader_identifier' => $reader->identifier]);

            return;
        }

        $readerKey = $this->readerKey($reader);
        $this->keypadBufferByReader[$readerKey] ??= [];
        $this->keypadBufferByReader[$readerKey][] = (string) $value;
        $this->keypadLastPressByReader[$readerKey] = $now;

        Log::debug('Buffered keypad digit from serial reader.', [
            'reader_identifier' => $reader->identifier,
            'digit' => $value,
            'buffer' => implode('', $this->keypadBufferByReader[$readerKey]),
        ]);
    }

    public function flushTimedOutKeypadBuffer(Reader $reader, ?float $now = null, ?string $ipAddress = null): void
    {
        $now ??= microtime(true);
        $readerKey = $this->readerKey($reader);

        if (($this->keypadBufferByReader[$readerKey] ?? []) === []) {
            return;
        }

        $lastPress = $this->keypadLastPressByReader[$readerKey] ?? 0.0;

        if (($now - $lastPress) <= $this->keypadTimeout($reader)) {
            return;
        }

        Log::info('Keypad timeout reached, flushing buffered code.', [
            'reader_identifier' => $reader->identifier,
        ]);

        $this->flushKeypadBuffer($reader, $now, $ipAddress);
    }

    private function flushKeypadBuffer(Reader $reader, float $now, ?string $ipAddress = null): void
    {
        $readerKey = $this->readerKey($reader);
        $buffer = $this->keypadBufferByReader[$readerKey] ?? [];

        if ($buffer === []) {
            return;
        }

        unset($this->keypadBufferByReader[$readerKey], $this->keypadLastPressByReader[$readerKey]);

        $code = implode('', $buffer);

        Log::info('Submitting buffered keypad code from serial reader.', [
            'reader_identifier' => $reader->identifier,
            'code' => $code,
            'timestamp' => $now,
        ]);

        $this->handleAccessRequest->validateCard($code, $reader->identifier, $ipAddress);
    }

    private function isDuplicateCard(Reader $reader, string $cardNumber, float $now): bool
    {
        $cacheKey = $this->readerKey($reader).':'.$cardNumber;
        $lastSeen = $this->lastSeenByCardAndReader[$cacheKey] ?? 0.0;

        if (($now - $lastSeen) < $this->duplicateWindow($reader)) {
            return true;
        }

        $this->lastSeenByCardAndReader[$cacheKey] = $now;

        return false;
    }

    private function isDuplicateDoorbell(Reader $reader, float $now): bool
    {
        $readerKey = $this->readerKey($reader);
        $lastSeen = $this->doorbellLastSeenByReader[$readerKey] ?? 0.0;

        if (($now - $lastSeen) < $this->doorbellDuplicateWindow($reader)) {
            return true;
        }

        $this->doorbellLastSeenByReader[$readerKey] = $now;

        return false;
    }

    private function readerKey(Reader $reader): string
    {
        return (string) ($reader->id ?? $reader->identifier);
    }

    private function serialConfig(Reader $reader, string $key, mixed $default): mixed
    {
        return data_get($reader->config, 'wiegand.'.$key, $default);
    }

    private function defaultInt(string $key, int $fallback): int
    {
        return max(0, (int) $this->settings->getInt($key, $fallback));
    }

    private function defaultFloat(string $key, float $fallback): float
    {
        return max(0.0, (float) $this->settings->get($key, $fallback));
    }

    private function cardMinValue(Reader $reader): int
    {
        $default = $this->defaultInt('wiegand.default_card_min_value', 15);

        return max(0, (int) $this->serialConfig($reader, 'card_min_value', $default));
    }

    private function doorbellValue(Reader $reader): int
    {
        $default = $this->defaultInt('wiegand.default_doorbell_value', 11);

        return max(0, (int) $this->serialConfig($reader, 'doorbell_value', $default));
    }

    private function duplicateWindow(Reader $reader): float
    {
        $default = $this->defaultFloat('wiegand.default_duplicate_window_seconds', 2.0);

        return max(0.0, (float) $this->serialConfig($reader, 'duplicate_window', $default));
    }

    private function doorbellDuplicateWindow(Reader $reader): float
    {
        $default = $this->defaultFloat('wiegand.default_doorbell_duplicate_window_seconds', 2.0);

        return max(0.0, (float) $this->serialConfig($reader, 'doorbell_duplicate_window', $default));
    }

    private function keypadTimeout(Reader $reader): float
    {
        $default = max(0.1, (float) $this->settings->get('wiegand.default_keypad_timeout_seconds', 3.0));

        return max(0.1, (float) $this->serialConfig($reader, 'keypad_timeout', $default));
    }
}
