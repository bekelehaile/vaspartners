<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ethio telecom NGBSS IVR access token — same pattern as bill_complaint AccessTokenService.
 */
class CrmAccessTokenService
{
    public function getToken(bool $forceRefresh = false): ?string
    {
        $baseUrl = (string) config('services.crm.access_token.base_url');
        $appKey = (string) config('services.crm.access_token.app_key');
        $secret = (string) config('services.crm.access_token.secret_key');

        if ($baseUrl === '' || $appKey === '' || $secret === '') {
            return null;
        }

        $cacheKey = 'vas:crm:access_token';

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($baseUrl, $appKey, $secret) {
            try {
                $timeout = max(5, (int) config('services.crm.access_token.timeout', 15));
                $response = Http::timeout($timeout)
                    ->connectTimeout(5)
                    ->retry(2, 200, throw: false)
                    ->withOptions(['verify' => app()->isProduction()])
                    ->acceptJson()
                    ->post($baseUrl, [
                        'appKey' => $appKey,
                        'secretKey' => $secret,
                    ]);

                if (! $response->successful()) {
                    Log::warning('CRM access token HTTP error', [
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $data = $response->json();
                if (($data['resultCode'] ?? null) === '0' && filled($data['token'] ?? null)) {
                    return (string) $data['token'];
                }

                Log::warning('CRM access token rejected', [
                    'resultCode' => $data['resultCode'] ?? null,
                ]);

                return null;
            } catch (Throwable $e) {
                Log::error('CRM access token exception', ['message' => $e->getMessage()]);

                return null;
            }
        });
    }
}
