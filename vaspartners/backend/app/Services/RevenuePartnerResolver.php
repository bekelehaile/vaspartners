<?php

namespace App\Services;

use App\Models\RevenuePartner;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolve a revenue partner from service_id and/or short_code with conflict checks.
 * Optionally scoped to partners owned by a given account manager.
 */
class RevenuePartnerResolver
{
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Find a partner by service ID, tolerating a dropped leading zero
     * (Excel/CSV often turns 0102132000005008 into 102132000005008).
     */
    protected function findByServiceId(Builder $query, string $serviceId): ?RevenuePartner
    {
        $exact = (clone $query)->where('service_id', $serviceId)->first();
        if ($exact) {
            return $exact;
        }

        // Only for long numeric service IDs — never for short codes.
        if (! preg_match('/^\d{10,}$/', $serviceId)) {
            return null;
        }

        $stripped = ltrim($serviceId, '0');
        if ($stripped === '') {
            return null;
        }

        $candidates = (clone $query)
            ->whereRaw("NULLIF(LTRIM(service_id, '0'), '') = ?", [$stripped])
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @return array{
     *   ok: true,
     *   partner: ?RevenuePartner,
     *   service_id: ?string,
     *   short_code: ?string,
     *   error: null
     * }|array{
     *   ok: false,
     *   partner: null,
     *   service_id: ?string,
     *   short_code: ?string,
     *   error: string
     * }
     */
    public function resolve(?string $serviceId, ?string $shortCode, ?int $ownerUserId = null): array
    {
        $serviceId = self::normalize($serviceId);
        $shortCode = self::normalize($shortCode);

        if ($serviceId === null && $shortCode === null) {
            return $this->fail('Provide service_id and/or short_code.', null, null);
        }

        $byServiceId = $serviceId !== null
            ? $this->findByServiceId($this->baseQuery($ownerUserId), $serviceId)
            : null;

        $byShortCode = null;
        if ($shortCode !== null) {
            $matches = $this->baseQuery($ownerUserId)
                ->where('short_code', $shortCode)
                ->limit(2)
                ->get();

            if ($matches->count() > 1) {
                return $this->fail(
                    "Short code {$shortCode} matches multiple of your partners.",
                    $serviceId,
                    $shortCode,
                );
            }

            $byShortCode = $matches->first();
        }

        if ($byServiceId && $byShortCode && (int) $byServiceId->id !== (int) $byShortCode->id) {
            return $this->fail(
                'service_id and short_code match different partners in your master list.',
                $serviceId,
                $shortCode,
            );
        }

        $partner = $byServiceId ?? $byShortCode;

        // Globally exists but not owned by this AM → treat as not found for matching.
        if (! $partner && $ownerUserId) {
            $elsewhere = $this->existsElsewhere($serviceId, $shortCode, $ownerUserId);
            if ($elsewhere) {
                return $this->fail(
                    'This service ID / short code belongs to another account manager.',
                    $serviceId,
                    $shortCode,
                );
            }
        }

        if ($partner) {
            // When CSV service_id matched via leading-zero tolerance, accept master service_id.
            $masterServiceId = self::normalize($partner->service_id);
            if ($serviceId !== null
                && $masterServiceId !== null
                && $masterServiceId !== $serviceId
                && ! $this->serviceIdsEquivalent($masterServiceId, $serviceId)) {
                return $this->fail(
                    "Short code matches partner {$partner->service_id}, but CSV service_id does not.",
                    $serviceId,
                    $shortCode,
                );
            }

            $masterShort = self::normalize($partner->short_code);
            if ($shortCode !== null && $masterShort !== null && $masterShort !== $shortCode) {
                return $this->fail(
                    "Service ID matches partner short code {$masterShort}, but CSV short_code does not.",
                    $serviceId,
                    $shortCode,
                );
            }
        }

        return [
            'ok' => true,
            'partner' => $partner,
            // Prefer master service_id when matched (keeps leading zero).
            'service_id' => $partner?->service_id ? self::normalize($partner->service_id) : $serviceId,
            'short_code' => $shortCode ?? self::normalize($partner?->short_code),
            'error' => null,
        ];
    }

    /**
     * @return array{
     *   ok: true,
     *   partner: ?RevenuePartner,
     *   service_id: ?string,
     *   short_code: ?string,
     *   error: null
     * }|array{
     *   ok: false,
     *   partner: null,
     *   service_id: ?string,
     *   short_code: ?string,
     *   error: string
     * }
     */
    public function resolveForUpsert(?string $serviceId, ?string $shortCode, ?int $ownerUserId = null): array
    {
        $resolved = $this->resolve($serviceId, $shortCode, $ownerUserId);
        if (! $resolved['ok']) {
            return $resolved;
        }

        $serviceId = $resolved['service_id'];
        $shortCode = $resolved['short_code'];

        if ($resolved['partner'] === null && $serviceId === null && $shortCode === null) {
            return $this->fail(
                'Provide service ID and/or short code to create a partner.',
                $serviceId,
                $shortCode,
            );
        }

        if ($resolved['partner'] === null && $shortCode !== null) {
            $taken = RevenuePartner::query()->where('short_code', $shortCode)->exists();
            if ($taken) {
                return $this->fail(
                    "Short code {$shortCode} is already used by another master partner.",
                    $serviceId,
                    $shortCode,
                );
            }
        }

        if ($resolved['partner'] === null && $serviceId !== null) {
            $taken = RevenuePartner::query()->where('service_id', $serviceId)->exists();
            if ($taken) {
                return $this->fail(
                    "Service ID {$serviceId} is already used by another master partner.",
                    $serviceId,
                    $shortCode,
                );
            }
        }

        return $resolved;
    }

    protected function baseQuery(?int $ownerUserId): Builder
    {
        $query = RevenuePartner::query();
        if ($ownerUserId) {
            // Owned by this AM, or unowned seed rows that can be claimed.
            $query->where(function ($q) use ($ownerUserId): void {
                $q->where('created_by_user_id', $ownerUserId)
                    ->orWhereNull('created_by_user_id');
            });
        }

        return $query;
    }

    protected function existsElsewhere(?string $serviceId, ?string $shortCode, int $ownerUserId): bool
    {
        return RevenuePartner::query()
            ->whereNotNull('created_by_user_id')
            ->where('created_by_user_id', '!=', $ownerUserId)
            ->where(function ($q) use ($serviceId, $shortCode): void {
                if ($serviceId !== null) {
                    $q->orWhere('service_id', $serviceId);
                    if (preg_match('/^\d{10,}$/', $serviceId)) {
                        $stripped = ltrim($serviceId, '0');
                        if ($stripped !== '') {
                            $q->orWhereRaw("NULLIF(LTRIM(service_id, '0'), '') = ?", [$stripped]);
                        }
                    }
                }
                if ($shortCode !== null) {
                    $q->orWhere('short_code', $shortCode);
                }
            })
            ->exists();
    }

    protected function serviceIdsEquivalent(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (! preg_match('/^\d{10,}$/', $a) || ! preg_match('/^\d{10,}$/', $b)) {
            return false;
        }

        $sa = ltrim($a, '0');
        $sb = ltrim($b, '0');

        return $sa !== '' && $sa === $sb;
    }

    /**
     * @return array{ok: false, partner: null, service_id: ?string, short_code: ?string, error: string}
     */
    protected function fail(string $error, ?string $serviceId, ?string $shortCode): array
    {
        return [
            'ok' => false,
            'partner' => null,
            'service_id' => $serviceId,
            'short_code' => $shortCode,
            'error' => $error,
        ];
    }
}
