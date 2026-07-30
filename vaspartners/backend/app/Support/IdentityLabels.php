<?php

namespace App\Support;

/**
 * Shared partner identity labels (Fayda / CRM / unverified).
 */
final class IdentityLabels
{
    public static function via(?string $via): string
    {
        return match (strtolower(trim((string) $via))) {
            'fayda' => 'Fayda',
            'crm' => 'CRM',
            default => '—',
        };
    }

    public static function isVerified(?string $via, bool $legacyFayda = false): bool
    {
        if (filled($via)) {
            return true;
        }

        return $legacyFayda;
    }
}
