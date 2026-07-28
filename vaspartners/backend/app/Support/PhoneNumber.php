<?php

namespace App\Support;

/**
 * Ethiopian mobile numbers for Ethio telecom SMS.
 *
 * Storage: last 9 digits (9xxxxxxxx / 7xxxxxxxx).
 * Gateway MSISDN: 2519xxxxxxxx (always country code 251).
 * Display E.164: +2519xxxxxxxx.
 */
final class PhoneNumber
{
    public const COUNTRY_CODE = '251';

    public static function normalize(mixed $phone): string
    {
        $digits = self::digitsOnly($phone);

        if ($digits === '') {
            return '';
        }

        // Strip international 00 prefix.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Strip Ethiopia country code when present.
        if (str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) >= 12) {
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        }

        // Strip national trunk 0 (0912… / 07…).
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) < 9) {
            return '';
        }

        return substr($digits, -9);
    }

    public static function normalizeNullable(mixed $phone): ?string
    {
        $normalized = self::normalize($phone);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * True only for Ethio telecom mobiles that can be sent as +251 / 251…
     */
    public static function isValidLocalMobile(mixed $phone): bool
    {
        if (! self::hasEthiopiaCountryOrNationalForm($phone)) {
            return false;
        }

        $normalized = self::normalize($phone);

        return (bool) preg_match('/^[97]\d{8}$/', $normalized);
    }

    /**
     * Gateway receiver without plus: 2519xxxxxxxx.
     */
    public static function toMsisdn251(mixed $phone): ?string
    {
        if (! self::isValidLocalMobile($phone)) {
            return null;
        }

        return self::COUNTRY_CODE.self::normalize($phone);
    }

    /**
     * E.164: +2519xxxxxxxx.
     */
    public static function toE164(mixed $phone): ?string
    {
        $msisdn = self::toMsisdn251($phone);

        return $msisdn !== null ? '+'.$msisdn : null;
    }

    /**
     * Reject numbers that look international but are not Ethiopia (+251 / 251).
     */
    public static function hasEthiopiaCountryOrNationalForm(mixed $phone): bool
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return false;
        }

        $digits = self::digitsOnly($raw);
        if ($digits === '') {
            return false;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Explicit non-Ethiopia country code (e.g. +1, +44, 254…).
        if (strlen($digits) > 10 && ! str_starts_with($digits, self::COUNTRY_CODE)) {
            return false;
        }

        // Explicit + prefix must be +251…
        if (str_starts_with($raw, '+') && ! str_starts_with(ltrim($raw, '+'), self::COUNTRY_CODE)) {
            $afterPlus = preg_replace('/\D+/', '', ltrim($raw, '+')) ?? '';
            if ($afterPlus !== '' && ! str_starts_with($afterPlus, self::COUNTRY_CODE)) {
                return false;
            }
        }

        return true;
    }

    private static function digitsOnly(mixed $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return preg_replace('/\D+/', '', trim((string) $phone)) ?? '';
    }
}
