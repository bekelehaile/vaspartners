<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ethio telecom SMS gateway (smsgw) — partner communications over SMS only.
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
            Log::warning('SMS skipped — phone is not a local Ethio telecom mobile', [
                'phone' => (string) $phone,
            ]);

            return;
        }

        $normalized = $this->normalizePhone($phone);
        SendSmsJob::dispatch($normalized, $message);
    }

    public function sendNow(string|int $phone, string $message): bool
    {
        $normalized = $this->normalizePhone($phone);
        $url = $this->buildSmsUrl($normalized, $message);

        try {
            $response = Http::timeout(15)->get($url);

            if (! $response->successful()) {
                Log::error('SMS gateway rejected request', [
                    'phone' => $normalized,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('SMS sent', ['phone' => $normalized]);

            return true;
        } catch (Throwable $e) {
            Log::error('SMS gateway request failed', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function buildSmsUrl(string $phone, string $message): string
    {
        $endpoint = (string) config('services.sms_endpoint');

        return sprintf(
            '%s%s&message=%s',
            $endpoint,
            urlencode('251'.$phone),
            urlencode($message)
        );
    }

    public function normalizePhone(string|int $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return substr($digits, -9);
    }

    public function ensurePhoneIsLocal(string|int $phone): bool
    {
        return (bool) preg_match('/^(\+251|251|0)?(9|7)\d{8}$/', (string) $phone);
    }
}
