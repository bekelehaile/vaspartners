<?php

namespace App\Support;

/**
 * Canonical phone storage: digits only, last 9 (Ethiopian mobile).
 * Example inputs 0912345678 / +251912345678 / 251912345678 → 912345678
 */
final class PhoneNumber
{
    public static function normalize(mixed $phone): string
    {
        if ($phone === null) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', trim((string) $phone)) ?? '';

        if ($digits === '') {
            return '';
        }

        return substr($digits, -9);
    }

    public static function normalizeNullable(mixed $phone): ?string
    {
        $normalized = self::normalize($phone);

        return $normalized !== '' ? $normalized : null;
    }

    public static function isValidLocalMobile(mixed $phone): bool
    {
        $normalized = self::normalize($phone);

        return (bool) preg_match('/^[97]\d{8}$/', $normalized);
    }
}
