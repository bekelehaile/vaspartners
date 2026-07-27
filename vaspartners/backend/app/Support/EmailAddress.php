<?php

namespace App\Support;

/** Canonical email storage: trim + lowercase. Empty → null. */
final class EmailAddress
{
    public static function normalize(mixed $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $email));

        return $normalized !== '' ? $normalized : null;
    }
}
