<?php

namespace App\Services\Etrade;

/**
 * Compare partner-entered company name with ERCA legal name.
 */
final class CompanyNameMatcher
{
    public static function normalize(string $name): string
    {
        $name = mb_strtoupper(trim($name), 'UTF-8');
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
            $shorter = strlen($a) <= strlen($b) ? $a : $b;
            $longer = strlen($a) > strlen($b) ? $a : $b;
            // Require substantial overlap (at least 70% of the longer name).
            if (strlen($shorter) >= 3 && (strlen($shorter) / max(1, strlen($longer))) >= 0.7) {
                return true;
            }
        }

        similar_text($a, $b, $percent);

        return $percent >= 88.0;
    }
}
