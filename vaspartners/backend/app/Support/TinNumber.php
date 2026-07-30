<?php

namespace App\Support;

/**
 * Ethiopian Tax Identification Number (TIN) — Ministry of Revenues / ERCA.
 *
 * Official format: exactly 10 numeric digits (includes a self-check digit).
 * Partners enter digits only; spaces and dashes are stripped on normalize.
 */
final class TinNumber
{
    public const LENGTH = 10;

    public static function normalize(mixed $tin): string
    {
        return preg_replace('/\D+/', '', (string) $tin) ?? '';
    }

    public static function normalizeNullable(mixed $tin): ?string
    {
        $normalized = self::normalize($tin);

        return $normalized !== '' ? $normalized : null;
    }

    public static function isValid(mixed $tin): bool
    {
        $digits = self::normalize($tin);

        if (strlen($digits) !== self::LENGTH) {
            return false;
        }

        if (! ctype_digit($digits)) {
            return false;
        }

        // Reject trivial / placeholder sequences.
        if (preg_match('/^(\d)\1{9}$/', $digits)) {
            return false;
        }

        if ($digits === '0123456789' || $digits === '1234567890') {
            return false;
        }

        return true;
    }

    public static function message(): string
    {
        return 'Enter a valid Ethiopian TIN number: exactly 10 digits (Ministry of Revenues / ERCA).';
    }
}
