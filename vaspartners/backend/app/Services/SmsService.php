<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Ethio telecom SMS gateway (smsgw) — partner communications over SMS only.
 *
 * All sends require a valid Ethiopian mobile (+251 / 251…) and respect rate limits.
 */
class SmsService
{
    public function send(string|int $phone, string $message): void
    {
        if (! config('notifications.enabled', true)) {
            Log::info('SMS skipped (SMS_ENABLED=false)', [
                'phone' => $this->normalizePhone($phone),
            ]);

            return;
        }

        if (! $this->ensurePhoneIsLocal($phone)) {
            Log::warning('SMS skipped — phone must be Ethiopian mobile (+251 / 09 / 07)', [
                'phone' => (string) $phone,
            ]);

            return;
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === '' || PhoneNumber::toMsisdn251($normalized) === null) {
            Log::warning('SMS skipped — could not build +251 MSISDN', [
                'phone' => (string) $phone,
            ]);

            return;
        }

        SendSmsJob::dispatch($normalized, $this->normalizeSmsBody($message));
    }

    /**
     * Immediate send (bulk / jobs). Always validates +251 and rate limits.
     */
    public function sendNow(string|int $phone, string $message): bool
    {
        return $this->sendNowInternal($phone, $message, consumeRateLimit: true);
    }

    /**
     * Same as sendNow but skips rate-limit hit (caller already consumed a slot).
     */
    public function sendNowBypassingRateLimit(string|int $phone, string $message): bool
    {
        return $this->sendNowInternal($phone, $message, consumeRateLimit: false);
    }

    private function sendNowInternal(string|int $phone, string $message, bool $consumeRateLimit): bool
    {
        if (! config('notifications.enabled', true)) {
            Log::info('SMS skipped (SMS_ENABLED=false)', [
                'phone' => $this->normalizePhone($phone),
            ]);

            return false;
        }

        if (! $this->ensurePhoneIsLocal($phone)) {
            Log::warning('SMS skipped — phone must be Ethiopian mobile (+251 / 09 / 07)', [
                'phone' => (string) $phone,
            ]);

            return false;
        }

        $normalized = $this->normalizePhone($phone);
        $msisdn = PhoneNumber::toMsisdn251($normalized);
        if ($msisdn === null) {
            Log::warning('SMS skipped — invalid +251 MSISDN', [
                'phone' => (string) $phone,
            ]);

            return false;
        }

        if ($consumeRateLimit && ! $this->consumeRateLimits($normalized)) {
            Log::warning('SMS rate limited', [
                'phone' => $normalized,
                'e164' => '+'.$msisdn,
            ]);

            return false;
        }

        $url = $this->buildSmsUrl($normalized, $this->normalizeSmsBody($message));

        try {
            $response = Http::timeout(15)->get($url);

            if (! $response->successful()) {
                Log::error('SMS gateway rejected request', [
                    'phone' => $normalized,
                    'msisdn' => $msisdn,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('SMS sent', [
                'phone' => $normalized,
                'msisdn' => $msisdn,
                'e164' => '+'.$msisdn,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('SMS gateway request failed', [
                'phone' => $normalized,
                'msisdn' => $msisdn,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build gateway URL. Receiver is always 251XXXXXXXXX (no bare local numbers).
     */
    public function buildSmsUrl(string $phone, string $message): string
    {
        $endpoint = (string) config('services.sms_endpoint');
        $msisdn = PhoneNumber::toMsisdn251($phone);

        if ($msisdn === null) {
            throw new \InvalidArgumentException('SMS receiver must be a valid +251 Ethiopian mobile.');
        }

        return sprintf(
            '%s%s&message=%s',
            $endpoint,
            urlencode($msisdn),
            urlencode($message)
        );
    }

    /**
     * Ethio telecom SMS gateway often mangles newlines (phones show "_" before the next line).
     * Collapse all line breaks / odd whitespace to single spaces.
     */
    public function normalizeSmsBody(string $message): string
    {
        $message = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $message);
        $message = preg_replace('/[ ]{2,}/u', ' ', $message) ?? $message;

        return trim($message);
    }

    public function normalizePhone(string|int|null $phone): string
    {
        return PhoneNumber::normalize($phone);
    }

    public function ensurePhoneIsLocal(string|int|null $phone): bool
    {
        return PhoneNumber::isValidLocalMobile($phone);
    }

    /**
     * Per-phone and global SMS throttles. Returns false when blocked.
     */
    public function consumeRateLimits(string $normalizedPhone): bool
    {
        $phoneMax = max(1, (int) config('notifications.sms_rate.per_phone.max', 20));
        $phoneDecay = max(1, (int) config('notifications.sms_rate.per_phone.decay_seconds', 3600));
        $globalMax = max(1, (int) config('notifications.sms_rate.global.max', 120));
        $globalDecay = max(1, (int) config('notifications.sms_rate.global.decay_seconds', 60));

        $phoneKey = 'sms:phone:'.$normalizedPhone;
        $globalKey = 'sms:global';

        if (RateLimiter::tooManyAttempts($phoneKey, $phoneMax)) {
            return false;
        }

        if (RateLimiter::tooManyAttempts($globalKey, $globalMax)) {
            return false;
        }

        RateLimiter::hit($phoneKey, $phoneDecay);
        RateLimiter::hit($globalKey, $globalDecay);

        return true;
    }

    public function remainingPhoneAttempts(string $normalizedPhone): int
    {
        $phoneMax = max(1, (int) config('notifications.sms_rate.per_phone.max', 20));

        return RateLimiter::remaining('sms:phone:'.$normalizedPhone, $phoneMax);
    }
}
