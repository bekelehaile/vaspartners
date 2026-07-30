<?php

namespace App\Services\Etrade;

/**
 * Compare partner-entered company name with ERCA legal name (case-insensitive).
 */
final class CompanyNameMatcher
{
    /**
     * Normalize for comparison: lowercase, strip punctuation, collapse spaces.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    public static function matches(string $entered, string $legal): bool
    {
        $a = self::normalize($entered);
        $b = self::normalize($legal);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        // Allow either string to contain the other after normalization (extra legal suffix).
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
            $longer = mb_strlen($a) > mb_strlen($b) ? $a : $b;
            // Require substantial overlap (at least 70% of the longer name).
            if (mb_strlen($shorter) >= 3 && (mb_strlen($shorter) / max(1, mb_strlen($longer))) >= 0.7) {
                return true;
            }
        }

        similar_text($a, $b, $percent);

        return $percent >= 88.0;
    }

    /**
     * Display casing like Laravel ucwords / Str::title (UTF-8 safe).
     */
    public static function titleCase(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
