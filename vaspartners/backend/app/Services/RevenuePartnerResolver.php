<?php

namespace App\Services;

use App\Models\RevenuePartner;

/**
 * Resolve a revenue partner from service_id and/or short_code with conflict checks.
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
    public function resolve(?string $serviceId, ?string $shortCode): array
    {
        $serviceId = self::normalize($serviceId);
        $shortCode = self::normalize($shortCode);

        if ($serviceId === null && $shortCode === null) {
            return $this->fail('Provide service_id and/or short_code.', null, null);
        }

        $byServiceId = $serviceId !== null
            ? RevenuePartner::query()->where('service_id', $serviceId)->first()
            : null;

        $byShortCode = null;
        if ($shortCode !== null) {
            $matches = RevenuePartner::query()
                ->where('short_code', $shortCode)
                ->limit(2)
                ->get();

            if ($matches->count() > 1) {
                return $this->fail(
                    "Short code {$shortCode} matches multiple master partners.",
                    $serviceId,
                    $shortCode,
                );
            }

            $byShortCode = $matches->first();
        }

        if ($byServiceId && $byShortCode && (int) $byServiceId->id !== (int) $byShortCode->id) {
            return $this->fail(
                'service_id and short_code match different master partners.',
                $serviceId,
                $shortCode,
            );
        }

        $partner = $byServiceId ?? $byShortCode;

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
     * Find an existing partner for master-list upsert, or null when creating new.
     *
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
    public function resolveForUpsert(?string $serviceId, ?string $shortCode): array
    {
        $resolved = $this->resolve($serviceId, $shortCode);
        if (! $resolved['ok']) {
            return $resolved;
        }

        $serviceId = $resolved['service_id'];
        $shortCode = $resolved['short_code'];

        // Creating a new master row always needs a service_id (unique business key).
        if ($resolved['partner'] === null && $serviceId === null) {
            return $this->fail(
                'service_id is required to create a new revenue partner (short_code alone is only for matching).',
                $serviceId,
                $shortCode,
            );
        }

        // If creating by service_id, short_code must not already belong to another partner.
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

        return $resolved;
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
