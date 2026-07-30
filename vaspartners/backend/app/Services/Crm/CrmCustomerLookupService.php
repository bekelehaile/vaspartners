<?php

namespace App\Services\Crm;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QueryCustAcctSubsData by MSISDN — same contract as bill_complaint CustomerAccountService.
 */
class CrmCustomerLookupService
{
    public function __construct(
        private readonly CrmAccessTokenService $tokens,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.crm.enabled', true)
            && filled(config('services.crm.query_endpoint'))
            && filled(config('services.crm.access_token.base_url'))
            && filled(config('services.crm.access_token.app_key'));
    }

    /**
     * @return array{
     *   found: bool,
     *   customer_name: ?string,
     *   customer_type: ?string,
     *   primary_offer_name: ?string,
     *   service_numbers: list<string>,
     *   region: ?string,
     *   zone: ?string,
     *   raw: array<string, mixed>
     * }|null  null when CRM disabled / unavailable
     */
    public function lookupByPhone(string $phone): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === '' || ! PhoneNumber::isValidLocalMobile($normalized)) {
            return [
                'found' => false,
                'customer_name' => null,
                'customer_type' => null,
                'primary_offer_name' => null,
                'service_numbers' => [],
                'region' => null,
                'zone' => null,
                'raw' => [],
            ];
        }

        $token = $this->tokens->getToken();
        if ($token === null) {
            return null;
        }

        $endpoint = (string) config('services.crm.query_endpoint');
        $timeout = max(5, (int) config('services.crm.timeout', 15));

        $payload = [
            'TotalRowNum' => 100,
            'BeginRowNum' => 1,
            'FetchRowNum' => 10,
            'QueryObj' => [
                'acctCode' => '',
                'serviceNumber' => $normalized,
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->retry(2, 200, throw: false)
                ->withOptions(['verify' => app()->isProduction()])
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);

            if (! $response->successful()) {
                Log::warning('CRM customer query HTTP error', [
                    'status' => $response->status(),
                    'phone_masked' => str_repeat('*', max(0, strlen($normalized) - 4)).substr($normalized, -4),
                ]);

                return null;
            }

            $raw = $response->json() ?? [];
            if (($raw['ReturnCode'] ?? null) !== '0') {
                return [
                    'found' => false,
                    'customer_name' => null,
                    'customer_type' => null,
                    'primary_offer_name' => null,
                    'service_numbers' => [],
                    'region' => null,
                    'zone' => null,
                    'raw' => is_array($raw) ? $raw : [],
                ];
            }

            $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
            if ($data === [] || (string) ($data['TotalRowNum'] ?? '0') === '0') {
                return [
                    'found' => false,
                    'customer_name' => null,
                    'customer_type' => null,
                    'primary_offer_name' => null,
                    'service_numbers' => [],
                    'region' => null,
                    'zone' => null,
                    'raw' => $raw,
                ];
            }

            $serviceNumbers = collect($data['serviceNumbers'] ?? [])
                ->pluck('serviceNumber')
                ->filter()
                ->map(fn ($n) => (string) $n)
                ->values()
                ->all();

            $name = trim((string) ($data['customerName'] ?? ''));

            return [
                'found' => $name !== '',
                'customer_name' => $name !== '' ? $name : null,
                'customer_type' => isset($data['customerType']) ? (string) $data['customerType'] : null,
                'primary_offer_name' => isset($data['serviceNumbers'][0]['primaryOfferName'])
                    ? (string) $data['serviceNumbers'][0]['primaryOfferName']
                    : null,
                'service_numbers' => $serviceNumbers,
                'region' => isset($data['administrativeRegionCity']) ? (string) $data['administrativeRegionCity'] : null,
                'zone' => isset($data['ethioZoneRegion']) ? (string) $data['ethioZoneRegion'] : null,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('CRM customer query exception', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
