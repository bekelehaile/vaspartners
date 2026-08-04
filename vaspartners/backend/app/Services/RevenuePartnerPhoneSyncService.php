<?php

namespace App\Services;

use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Fill revenue partner phones from an external Service ID → phone map (e.g. Abay API export).
 */
class RevenuePartnerPhoneSyncService
{
    /** @var array<string, int|null> */
    protected array $accountManagerCache = [];

    /**
     * @param  iterable<array{service_id?: ?string, short_code?: ?string, phone?: ?string, phone_raw?: ?string, account_manager?: ?string|int, partner_name?: ?string, service?: ?string, vas_service?: ?string}>  $rows
     * @return array{
     *   total: int,
     *   updated: int,
     *   already_had_phone: int,
     *   invalid_phone: int,
     *   not_found: int,
     *   created: int,
     *   skipped_na: int,
     *   assigned_am: int,
     *   unknown_am: int,
     *   not_found_keys: list<string>,
     *   invalid_keys: list<string>,
     *   unknown_am_names: list<string>,
     *   created_keys: list<string>
     * }
     */
    public function syncFromRows(
        iterable $rows,
        bool $overwrite = false,
        ?int $accountManagerUserId = null,
        bool $createMissing = false,
        ?int $defaultVasServiceId = null,
    ): array {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'already_had_phone' => 0,
            'invalid_phone' => 0,
            'not_found' => 0,
            'created' => 0,
            'skipped_na' => 0,
            'assigned_am' => 0,
            'unknown_am' => 0,
            'not_found_keys' => [],
            'invalid_keys' => [],
            'unknown_am_names' => [],
            'created_keys' => [],
        ];

        foreach ($rows as $row) {
            $stats['total']++;

            $serviceId = RevenuePartnerResolver::normalize($row['service_id'] ?? null);
            $shortCode = RevenuePartnerResolver::normalize($row['short_code'] ?? null);
            $rawPhone = $row['phone'] ?? $row['phone_raw'] ?? null;

            if ($serviceId === null && $shortCode === null) {
                $stats['not_found']++;
                $stats['not_found_keys'][] = '(blank key)';

                continue;
            }

            // Single-column Abay files put Teleplay* in Service ID — treat as short code when non-numeric.
            if ($serviceId !== null && $shortCode === null && ! ctype_digit($serviceId)) {
                $shortCode = $serviceId;
            }

            $phoneRaw = is_string($rawPhone) || is_numeric($rawPhone) ? trim((string) $rawPhone) : '';
            $phone = null;
            $skipPhone = false;
            if ($phoneRaw === '' || strtoupper($phoneRaw) === 'NA') {
                $skipPhone = true;
                $stats['skipped_na']++;
            } else {
                // Digits only, no spaces before/after/between.
                $phone = PhoneNumber::normalizeNullable($phoneRaw);
                if ($phone === null || ! PhoneNumber::isValidLocalMobile($phone)) {
                    $stats['invalid_phone']++;
                    $stats['invalid_keys'][] = $serviceId ?? $shortCode ?? '?';
                    $skipPhone = true;
                }
            }

            $rowAm = $row['account_manager'] ?? $row['am'] ?? null;
            $amId = $accountManagerUserId;
            if ($rowAm !== null && trim((string) $rowAm) !== '') {
                $resolved = $this->resolveAccountManagerId($rowAm);
                if ($resolved === null) {
                    $stats['unknown_am']++;
                    $stats['unknown_am_names'][] = trim((string) $rowAm);
                } else {
                    $amId = $resolved;
                }
            }

            $partner = $this->findPartner($serviceId, $shortCode);
            if (! $partner) {
                if (! $createMissing) {
                    $stats['not_found']++;
                    $stats['not_found_keys'][] = $serviceId ?? $shortCode ?? '?';

                    continue;
                }

                $partner = $this->createMissingPartner($row, $serviceId, $shortCode, $skipPhone ? null : $phone, $amId, $defaultVasServiceId);
                if (! $partner) {
                    $stats['not_found']++;
                    $stats['not_found_keys'][] = $serviceId ?? $shortCode ?? '?';

                    continue;
                }

                $stats['created']++;
                $stats['created_keys'][] = $partner->service_id ?: $partner->short_code ?: '?';
                if ($amId) {
                    $stats['assigned_am']++;
                }
                if (! $skipPhone && $phone !== null) {
                    $stats['updated']++;
                }

                continue;
            }

            $dirty = false;

            if ($amId && (int) $partner->created_by_user_id !== $amId) {
                $partner->created_by_user_id = $amId;
                $dirty = true;
                $stats['assigned_am']++;
            }

            if (! $skipPhone && $phone !== null) {
                $current = PhoneNumber::normalizeNullable($partner->phone);
                if ($overwrite || ! filled($current) || ! PhoneNumber::isValidLocalMobile($current)) {
                    $partner->phone = $phone;
                    $dirty = true;
                    $stats['updated']++;
                } elseif ($current !== $phone && $overwrite) {
                    $partner->phone = $phone;
                    $dirty = true;
                    $stats['updated']++;
                } elseif ($current !== (string) $partner->phone) {
                    // Re-normalize stored value if it still has spaces / formatting.
                    $partner->phone = $current;
                    $dirty = true;
                    $stats['updated']++;
                } else {
                    $stats['already_had_phone']++;
                }
            }

            if ($dirty) {
                DB::transaction(function () use ($partner): void {
                    $partner->save();
                });
            }
        }

        $stats['not_found_keys'] = array_values(array_unique($stats['not_found_keys']));
        $stats['invalid_keys'] = array_values(array_unique($stats['invalid_keys']));
        $stats['unknown_am_names'] = array_values(array_unique($stats['unknown_am_names']));
        $stats['created_keys'] = array_values(array_unique($stats['created_keys']));

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function createMissingPartner(
        array $row,
        ?string $serviceId,
        ?string $shortCode,
        ?string $phone,
        ?int $accountManagerUserId,
        ?int $defaultVasServiceId,
    ): ?RevenuePartner {
        $createServiceId = ($serviceId !== null && ctype_digit($serviceId)) ? $serviceId : null;
        $createShortCode = $shortCode;
        if ($createShortCode === null && $serviceId !== null && ! ctype_digit($serviceId)) {
            $createShortCode = $serviceId;
        }

        if ($createServiceId === null && $createShortCode === null) {
            return null;
        }

        // Avoid colliding with unique indexes.
        if ($createServiceId && RevenuePartner::query()->where('service_id', $createServiceId)->exists()) {
            return $this->findPartner($createServiceId, null);
        }
        if ($createShortCode && RevenuePartner::query()->where('short_code', $createShortCode)->exists()) {
            return $this->findPartner(null, $createShortCode);
        }

        $phoneSibling = null;
        if ($phone !== null) {
            $phoneSibling = RevenuePartner::query()
                ->where('phone', $phone)
                ->orderBy('id')
                ->first();
        }

        $partnerName = trim((string) ($row['partner_name'] ?? $row['partner name'] ?? ''));
        if ($partnerName === '') {
            $partnerName = trim((string) ($phoneSibling?->partner_name ?? ''));
        }
        if ($partnerName === '') {
            $partnerName = 'Revenue partner '.($createServiceId ?? $createShortCode);
        }

        $vasServiceId = $this->resolveVasServiceId(
            $row['vas_service'] ?? $row['service'] ?? $row['catalog_service'] ?? null,
            $defaultVasServiceId,
            $phoneSibling?->vas_service_id,
        );
        if (! $vasServiceId) {
            return null;
        }

        return DB::transaction(function () use (
            $createServiceId,
            $createShortCode,
            $partnerName,
            $phone,
            $accountManagerUserId,
            $vasServiceId,
        ): RevenuePartner {
            $partner = new RevenuePartner([
                'service_id' => $createServiceId,
                'short_code' => $createShortCode,
                'partner_name' => $partnerName,
                'phone' => $phone,
                'vas_service_id' => $vasServiceId,
                'created_by_user_id' => $accountManagerUserId,
                'is_active' => true,
            ]);
            $partner->save();

            return $partner->fresh();
        });
    }

    protected function resolveVasServiceId(mixed $raw, ?int $defaultVasServiceId, mixed $fallbackId): ?int
    {
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        $label = is_string($raw) ? mb_strtolower(trim($raw)) : '';
        $map = [
            'api' => 'api',
            'mt' => 'mt-mobile-terminated-premium',
            'mt/ mobile terminated premium' => 'mt-mobile-terminated-premium',
            'mo' => 'mo-mobile-originating',
            'mo(mobile originating)' => 'mo-mobile-originating',
            'crbt' => 'crbt',
            'voice-premium' => 'voice-premium',
            'voice premium' => 'voice-premium',
            'sms-premium' => 'sms-premium',
            'sms premium' => 'sms-premium',
            'ussd-premium' => 'ussd-premium',
            'ussd premium' => 'ussd-premium',
        ];
        if ($label !== '' && isset($map[$label])) {
            $id = \App\Models\Service::query()->where('slug', $map[$label])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if ($defaultVasServiceId) {
            return $defaultVasServiceId;
        }

        if (is_numeric($fallbackId) && (int) $fallbackId > 0) {
            return (int) $fallbackId;
        }

        $apiId = \App\Models\Service::query()->where('slug', 'api')->value('id');

        return $apiId ? (int) $apiId : null;
    }

    public function resolveAccountManagerId(int|string|null $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $key = trim((string) $raw);
        if ($key === '') {
            return null;
        }

        $cacheKey = mb_strtolower($key);
        if (array_key_exists($cacheKey, $this->accountManagerCache)) {
            return $this->accountManagerCache[$cacheKey];
        }

        if (ctype_digit($key)) {
            $user = User::query()->find((int) $key);
            $this->accountManagerCache[$cacheKey] = $user?->id;

            return $this->accountManagerCache[$cacheKey];
        }

        // Common CSV nicknames → staff emails used in this portal.
        $aliases = [
            'samuel' => 'samuel.tsehay@ethiotelecom.et',
            'meskerem' => 'meskerem.tamene@ethiotelecom.et',
            'selome' => 'selome.tilahun@ethiotelecom.et',
            'abayneh' => 'abayneh.mekonnen@ethiotelecom.et',
            'aziza' => 'aziza.ali@ethiotelecom.et',
            'kalkidan' => 'kalkidan.sahle@ethiotelecom.et',
        ];
        if (isset($aliases[$cacheKey])) {
            $user = User::query()->where('email', $aliases[$cacheKey])->first();
            if ($user) {
                $this->accountManagerCache[$cacheKey] = (int) $user->id;

                return $this->accountManagerCache[$cacheKey];
            }
        }

        $user = User::query()
            ->whereRaw('lower(name) = ?', [$cacheKey])
            ->orWhereRaw('lower(email) = ?', [$cacheKey])
            ->orWhereRaw('lower(username) = ?', [$cacheKey])
            ->first();

        if ($user) {
            $this->accountManagerCache[$cacheKey] = (int) $user->id;

            return $this->accountManagerCache[$cacheKey];
        }

        $matches = User::query()
            ->where('name', 'ilike', $key.'%')
            ->orWhere('name', 'ilike', '% '.$key.'%')
            ->orderBy('id')
            ->get();

        if ($matches->count() === 1) {
            $this->accountManagerCache[$cacheKey] = (int) $matches->first()->id;

            return $this->accountManagerCache[$cacheKey];
        }

        // Prefer ethiotelecom.et when several first-name matches exist.
        $preferred = $matches->first(
            fn (User $u) => str_ends_with(mb_strtolower((string) $u->email), '@ethiotelecom.et')
        );
        if ($preferred) {
            $this->accountManagerCache[$cacheKey] = (int) $preferred->id;

            return $this->accountManagerCache[$cacheKey];
        }

        $this->accountManagerCache[$cacheKey] = $matches->first()?->id;

        return $this->accountManagerCache[$cacheKey];
    }

    public function findPartner(?string $serviceId, ?string $shortCode): ?RevenuePartner
    {
        $candidates = [];
        foreach ([$serviceId, $shortCode] as $key) {
            if ($key === null || $key === '') {
                continue;
            }
            $candidates[] = $key;
            // CSV exports often drop leading zeros that Excel / Tele systems keep.
            $stripped = ltrim($key, '0');
            if ($stripped !== '' && $stripped !== $key) {
                $candidates[] = $stripped;
            }
            foreach (['0'.$key, '00'.$key, '000'.$key] as $padded) {
                $candidates[] = $padded;
            }
            // Seeded rows sometimes keep a trailing 0 Excel dropped.
            $candidates[] = $key.'0';
            if ($stripped !== '') {
                $candidates[] = $stripped.'0';
            }
        }
        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $key) {
            $partner = RevenuePartner::query()
                ->where(function ($q) use ($key): void {
                    $q->where('service_id', $key)->orWhere('short_code', $key);
                })
                ->orderBy('id')
                ->first();
            if ($partner) {
                return $partner;
            }
        }

        // Last resort: compare without leading zeros on either side.
        foreach (array_filter([$serviceId, $shortCode]) as $key) {
            $stripped = ltrim((string) $key, '0');
            if ($stripped === '') {
                continue;
            }
            $partner = RevenuePartner::query()
                ->where(function ($q) use ($stripped): void {
                    $q->whereRaw("ltrim(service_id, '0') = ?", [$stripped])
                        ->orWhereRaw("ltrim(coalesce(short_code, ''), '0') = ?", [$stripped]);
                })
                ->orderBy('id')
                ->first();
            if ($partner) {
                return $partner;
            }
        }

        return null;
    }
}
