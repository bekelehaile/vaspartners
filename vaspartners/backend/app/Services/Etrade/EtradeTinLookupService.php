<?php

namespace App\Services\Etrade;

use App\Support\TinNumber;
use App\Models\AppSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Looks up taxpayer / company info by Ethiopian TIN number via eTrade (etrade.gov.et).
 *
 * Upstream (working as of 2026-07):
 *   GET {base}/api/Tin/checkTin/{tin}
 *   GET {base}/api/Registration/GetRegistrationsByTin/{tin}
 *
 * Note: https://etrade.gov.et/api/v1/taxpayers/tin/{tin} currently returns 404;
 * paths are configurable via ETRADE_* env if the official route changes.
 */
class EtradeTinLookupService
{
    public function enabled(): bool
    {
        if (AppSetting::ercaTinInMaintenance()) {
            return false;
        }

        return (bool) config('services.etrade.enabled', true)
            && filled(config('services.etrade.base_url'));
    }

    /** Partner-facing copy when lookup is off or upstream is unreachable. */
    public function unavailableMessage(): string
    {
        return AppSetting::ercaTinOutageMessage();
    }

    /**
     * Admin diagnostic: live upstream call, ignores App settings maintenance mode.
     * Does not write "unavailable" into the partner result cache.
     *
     * @return array{
     *   ok: bool,
     *   status: string,
     *   tin: string,
     *   found: bool,
     *   legal_name: ?string,
     *   business_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string,
     *   message: string,
     *   from_cache: bool
     * }
     */
    public function adminProbe(string $tin, bool $useCache = false): array
    {
        $normalized = TinNumber::normalize($tin);

        if (! TinNumber::isValid($normalized)) {
            return [
                'ok' => false,
                'status' => 'invalid_tin',
                'tin' => $normalized,
                'found' => false,
                'legal_name' => null,
                'business_name' => null,
                'entity_type' => null,
                'tax_centre' => null,
                'region' => null,
                'city' => null,
                'message' => TinNumber::message(),
                'from_cache' => false,
            ];
        }

        $configLive = (bool) config('services.etrade.enabled', true)
            && filled(config('services.etrade.base_url'));

        if (! $configLive) {
            return [
                'ok' => false,
                'status' => 'disabled',
                'tin' => $normalized,
                'found' => false,
                'legal_name' => null,
                'business_name' => null,
                'entity_type' => null,
                'tax_centre' => null,
                'region' => null,
                'city' => null,
                'message' => 'eTrade is disabled in server config (ETRADE_ENABLED / ETRADE_BASE_URL).',
                'from_cache' => false,
            ];
        }

        $cacheKey = $this->resultCacheKey($normalized);
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('found', $cached)) {
                return $this->probePayload($cached, fromCache: true, ok: empty($cached['raw']['unavailable']));
            }
        } else {
            Cache::forget($cacheKey);
        }

        try {
            $taxpayerRows = $this->fetchJson($this->url(config('services.etrade.tin_path'), $normalized));
            $registrationRows = [];

            if ((bool) config('services.etrade.include_registrations', true)) {
                $registrationRows = $this->fetchJson(
                    $this->url(config('services.etrade.registrations_path'), $normalized),
                ) ?? [];
            }

            $result = $this->mapResult($normalized, $taxpayerRows, is_array($registrationRows) ? $registrationRows : []);
            $this->rememberResult($normalized, $result);

            return $this->probePayload($result, fromCache: false, ok: true);
        } catch (Throwable $e) {
            Log::error('eTrade TIN number admin probe failed', [
                'tin' => $normalized,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 'upstream_error',
                'tin' => $normalized,
                'found' => false,
                'legal_name' => null,
                'business_name' => null,
                'entity_type' => null,
                'tax_centre' => null,
                'region' => null,
                'city' => null,
                'message' => 'Upstream error: '.$e->getMessage(),
                'from_cache' => false,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{
     *   ok: bool,
     *   status: string,
     *   tin: string,
     *   found: bool,
     *   legal_name: ?string,
     *   business_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string,
     *   message: string,
     *   from_cache: bool
     * }
     */
    protected function probePayload(array $result, bool $fromCache, bool $ok): array
    {
        if (! empty($result['raw']['unavailable'])) {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'tin' => (string) ($result['tin'] ?? ''),
                'found' => false,
                'legal_name' => null,
                'business_name' => null,
                'entity_type' => null,
                'tax_centre' => null,
                'region' => null,
                'city' => null,
                'message' => $this->unavailableMessage(),
                'from_cache' => $fromCache,
            ];
        }

        $found = (bool) ($result['found'] ?? false);

        return [
            'ok' => $ok,
            'status' => $found ? 'found' : 'not_found',
            'tin' => (string) ($result['tin'] ?? ''),
            'found' => $found,
            'legal_name' => $result['legal_name'] ?? null,
            'business_name' => $result['business_name'] ?? null,
            'entity_type' => $result['entity_type'] ?? null,
            'tax_centre' => $result['tax_centre'] ?? null,
            'region' => $result['region'] ?? null,
            'city' => $result['city'] ?? null,
            'message' => $found
                ? 'TIN number found in ERCA.'
                : 'No taxpayer found for this TIN number in ERCA.',
            'from_cache' => $fromCache,
        ];
    }

    /**
     * @return array{
     *   found: bool,
     *   tin: string,
     *   legal_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string,
     *   locality: ?string,
     *   kebele: ?string,
     *   house_no: ?string,
     *   phone: ?string,
     *   mobile: ?string,
     *   email: ?string,
     *   entry_date: ?string,
     *   business_name: ?string,
     *   business_name_am: ?string,
     *   registrations: list<array<string, mixed>>,
     *   raw: array{taxpayer: mixed, registrations: mixed}
     * }
     */
    public function resultCacheKey(string $tin): string
    {
        return 'etrade:tin-result:'.TinNumber::normalize($tin);
    }

    public function hasCachedResult(string $tin): bool
    {
        $normalized = TinNumber::normalize($tin);
        if (! TinNumber::isValid($normalized)) {
            return false;
        }

        return Cache::has($this->resultCacheKey($normalized));
    }

    public function lookup(string $tin): array
    {
        $normalized = TinNumber::normalize($tin);

        if (! TinNumber::isValid($normalized)) {
            return $this->emptyResult($normalized);
        }

        if (! $this->enabled()) {
            return $this->emptyResult($normalized, unavailable: true);
        }

        $cacheKey = $this->resultCacheKey($normalized);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('found', $cached)) {
            return $cached;
        }

        try {
            $taxpayerRows = $this->fetchJson($this->url(config('services.etrade.tin_path'), $normalized));
            $registrationRows = [];

            if ((bool) config('services.etrade.include_registrations', true)) {
                $registrationRows = $this->fetchJson(
                    $this->url(config('services.etrade.registrations_path'), $normalized),
                ) ?? [];
            }

            $result = $this->mapResult($normalized, $taxpayerRows, is_array($registrationRows) ? $registrationRows : []);
            $this->rememberResult($normalized, $result);

            return $result;
        } catch (Throwable $e) {
            Log::error('eTrade TIN number lookup failed', [
                'tin' => $normalized,
                'message' => $e->getMessage(),
            ]);

            $unavailable = $this->emptyResult($normalized, unavailable: true);
            $seconds = max(30, (int) config('services.etrade.tin_unavailable_cache_seconds', 90));
            Cache::put($cacheKey, $unavailable, now()->addSeconds($seconds));

            return $unavailable;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function rememberResult(string $tin, array $result): void
    {
        if (! empty($result['raw']['unavailable'])) {
            $seconds = max(30, (int) config('services.etrade.tin_unavailable_cache_seconds', 90));
            Cache::put($this->resultCacheKey($tin), $result, now()->addSeconds($seconds));

            return;
        }

        if (! ($result['found'] ?? false)) {
            $minutes = max(5, (int) config('services.etrade.tin_not_found_cache_minutes', 60));
            Cache::put($this->resultCacheKey($tin), $result, now()->addMinutes($minutes));

            return;
        }

        $hours = max(1, (int) config('services.etrade.tin_cache_hours', 6));
        Cache::put($this->resultCacheKey($tin), $result, now()->addHours($hours));
    }

    protected function client(): PendingRequest
    {
        $timeout = max(5, (int) config('services.etrade.timeout', 20));

        return Http::timeout($timeout)
            ->connectTimeout(5)
            ->retry(2, 250, throw: false)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'VASPartners/1.0'])
            ->withOptions([
                'verify' => (bool) config('services.etrade.verify_ssl', false),
            ]);
    }

    protected function url(string $pathTemplate, string $tin): string
    {
        $base = rtrim((string) config('services.etrade.base_url'), '/');
        $path = str_replace('{tin}', rawurlencode($tin), $pathTemplate);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $base.$path;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchJson(string $url): ?array
    {
        $response = $this->client()->get($url);

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            Log::warning('eTrade TIN number HTTP error', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException('eTrade returned HTTP '.$response->status());
        }

        $json = $response->json();

        // Exists endpoints return bare true/false.
        if (is_bool($json)) {
            return $json ? [['exists' => true]] : [];
        }

        if ($json === null) {
            return [];
        }

        // Empty list from upstream.
        if ($json === []) {
            return [];
        }

        if (array_is_list($json) && isset($json[0]) && is_array($json[0])) {
            /** @var array<int, array<string, mixed>> $json */
            return $json;
        }

        if (is_array($json)) {
            return [$json];
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $taxpayerRows
     * @param  array<int, array<string, mixed>>  $registrationRows
     * @return array{
     *   found: bool,
     *   tin: string,
     *   legal_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string,
     *   locality: ?string,
     *   kebele: ?string,
     *   house_no: ?string,
     *   phone: ?string,
     *   mobile: ?string,
     *   email: ?string,
     *   entry_date: ?string,
     *   business_name: ?string,
     *   business_name_am: ?string,
     *   registrations: list<array<string, mixed>>,
     *   raw: array{taxpayer: mixed, registrations: mixed}
     * }
     */
    protected function mapResult(string $tin, ?array $taxpayerRows, array $registrationRows): array
    {
        $rows = $taxpayerRows ?? [];
        $primary = $this->pickLatestTaxpayerRow($rows);
        $registration = $this->pickLatestRegistration($registrationRows);

        $legalName = $this->englishName($primary) ?? $this->amharicName($primary);
        $legalNameAm = $this->amharicName($primary);
        $businessName = $this->stringOrNull($registration['BusinessName'] ?? null)
            ?? $this->stringOrNull($registration['BusinessNameAmh'] ?? null)
            ?? $legalName;
        $businessNameAm = $this->stringOrNull($registration['BusinessNameAmh'] ?? null) ?? $legalNameAm;

        $found = $primary !== [] || $registration !== [];

        return [
            'found' => $found,
            'tin' => $tin,
            'legal_name' => $legalName,
            'entity_type' => $this->stringOrNull($primary['ENT_TYPE_DESC'] ?? null),
            'tax_centre' => $this->stringOrNull($primary['TAX_CENTRE_DESC'] ?? null),
            'region' => $this->stringOrNull($primary['PARISH_NAME'] ?? null),
            'city' => $this->stringOrNull($primary['CITY_NAME'] ?? null),
            'locality' => $this->stringOrNull($primary['LOCALITY_DESC'] ?? null),
            'kebele' => $this->stringOrNull($primary['KEBELE_DESC'] ?? null),
            'house_no' => $this->stringOrNull($primary['HOUSE_NO'] ?? null),
            'phone' => $this->stringOrNull($primary['PHONE_NO'] ?? null),
            'mobile' => $this->stringOrNull($primary['MOBILE_PHONE'] ?? null),
            'email' => $this->stringOrNull($primary['ADDRESS_E_MAIL'] ?? null),
            'entry_date' => $this->stringOrNull($primary['ENTRY_DATE'] ?? null),
            'business_name' => $businessName,
            'business_name_am' => $businessNameAm,
            'registrations' => array_values(array_map(
                fn (array $row): array => [
                    'id' => $row['Id'] ?? null,
                    'tin' => $row['Tin'] ?? $tin,
                    'business_name' => $row['BusinessName'] ?? null,
                    'business_name_am' => $row['BusinessNameAmh'] ?? null,
                    'reg_date' => $row['RegDate'] ?? $row['DateRegistered'] ?? null,
                    'paid_up_capital' => $row['PaidUpCapital'] ?? null,
                    'legal_condition' => $row['LegalCondtion'] ?? null,
                ],
                $registrationRows,
            )),
            'raw' => [
                'taxpayer' => $rows,
                'registrations' => $registrationRows,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function pickLatestTaxpayerRow(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) ($b['ENTRY_DATE'] ?? ''), (string) ($a['ENTRY_DATE'] ?? ''));
        });

        return $rows[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function pickLatestRegistration(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp(
                (string) ($b['RegDate'] ?? $b['DateRegistered'] ?? ''),
                (string) ($a['RegDate'] ?? $a['DateRegistered'] ?? ''),
            );
        });

        return $rows[0];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function englishName(array $row): ?string
    {
        $parts = array_filter([
            $this->stringOrNull($row['FIRSTNAME'] ?? null),
            $this->stringOrNull($row['MIDDLENAME'] ?? null),
            $this->stringOrNull($row['LASTNAME'] ?? null),
        ], fn (?string $p): bool => $p !== null && $p !== '');

        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function amharicName(array $row): ?string
    {
        $parts = array_filter([
            $this->stringOrNull($row['FIRSTNAME_S'] ?? $row['FIRSTNAME_F'] ?? null),
            $this->stringOrNull($row['MIDDLENAME_S'] ?? $row['MIDDLENAME_F'] ?? null),
            $this->stringOrNull($row['LASTNAME_S'] ?? $row['LASTNAME_F'] ?? null),
        ], fn (?string $p): bool => $p !== null && $p !== '');

        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @return array{
     *   found: bool,
     *   tin: string,
     *   legal_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string,
     *   locality: ?string,
     *   kebele: ?string,
     *   house_no: ?string,
     *   phone: ?string,
     *   mobile: ?string,
     *   email: ?string,
     *   entry_date: ?string,
     *   business_name: ?string,
     *   business_name_am: ?string,
     *   registrations: list<array<string, mixed>>,
     *   raw: array{taxpayer: mixed, registrations: mixed}
     * }
     */
    protected function emptyResult(string $tin, bool $unavailable = false): array
    {
        return [
            'found' => false,
            'tin' => $tin,
            'legal_name' => null,
            'entity_type' => null,
            'tax_centre' => null,
            'region' => null,
            'city' => null,
            'locality' => null,
            'kebele' => null,
            'house_no' => null,
            'phone' => null,
            'mobile' => null,
            'email' => null,
            'entry_date' => null,
            'business_name' => null,
            'business_name_am' => null,
            'registrations' => [],
            'raw' => [
                'taxpayer' => [],
                'registrations' => [],
                'unavailable' => $unavailable,
            ],
        ];
    }
}
