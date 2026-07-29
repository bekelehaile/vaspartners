<?php

namespace App\Services;

use App\Models\RevenuePartner;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Fill revenue partner phones from an external Service ID → phone map (e.g. Abay API export).
 */
class RevenuePartnerPhoneSyncService
{
    /**
     * @param  iterable<array{service_id?: ?string, short_code?: ?string, phone?: ?string, phone_raw?: ?string}>  $rows
     * @return array{
     *   total: int,
     *   updated: int,
     *   already_had_phone: int,
     *   invalid_phone: int,
     *   not_found: int,
     *   skipped_na: int,
     *   assigned_am: int,
     *   not_found_keys: list<string>,
     *   invalid_keys: list<string>
     * }
     */
    public function syncFromRows(iterable $rows, bool $overwrite = false, ?int $accountManagerUserId = null): array
    {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'already_had_phone' => 0,
            'invalid_phone' => 0,
            'not_found' => 0,
            'skipped_na' => 0,
            'assigned_am' => 0,
            'not_found_keys' => [],
            'invalid_keys' => [],
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

            $partner = $this->findPartner($serviceId, $shortCode);
            if (! $partner) {
                $stats['not_found']++;
                $stats['not_found_keys'][] = $serviceId ?? $shortCode ?? '?';

                continue;
            }

            $dirty = false;

            if ($accountManagerUserId && (int) $partner->created_by_user_id !== $accountManagerUserId) {
                $partner->created_by_user_id = $accountManagerUserId;
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

        return $stats;
    }

    public function findPartner(?string $serviceId, ?string $shortCode): ?RevenuePartner
    {
        $candidates = array_values(array_filter([
            $serviceId,
            $shortCode,
            // Excel sometimes drops a trailing digit; our seed may keep …0 suffix.
            $serviceId !== null ? $serviceId.'0' : null,
            $shortCode !== null ? $shortCode.'0' : null,
        ]));

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

        return null;
    }
}
