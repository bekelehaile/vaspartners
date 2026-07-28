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
            ? $this->baseQuery($ownerUserId)->where('service_id', $serviceId)->first()
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
            if ($serviceId !== null && $partner->service_id !== $serviceId) {
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
            'service_id' => $serviceId ?? $partner?->service_id,
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

        if ($resolved['partner'] === null && $serviceId === null) {
            return $this->fail(
                'service_id is required to create a new revenue partner (short_code alone is only for matching).',
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
            $query->where('created_by_user_id', $ownerUserId);
        }

        return $query;
    }

    protected function existsElsewhere(?string $serviceId, ?string $shortCode, int $ownerUserId): bool
    {
        return RevenuePartner::query()
            ->where('created_by_user_id', '!=', $ownerUserId)
            ->where(function ($q) use ($serviceId, $shortCode): void {
                if ($serviceId !== null) {
                    $q->orWhere('service_id', $serviceId);
                }
                if ($shortCode !== null) {
                    $q->orWhere('short_code', $shortCode);
                }
            })
            ->exists();
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
