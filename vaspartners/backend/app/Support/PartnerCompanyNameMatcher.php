<?php

namespace App\Support;

/**
 * Fuzzy match finance revenue partner_name ↔ portal company name.
 * Used so shared SMS contact phones (Abay) do not leak other partners' revenue.
 */
final class PartnerCompanyNameMatcher
{
    /**
     * Legal / noise suffixes stripped before comparing.
     *
     * @var list<string>
     */
    private const STRIP_TOKENS = [
        'plc',
        'ltd',
        'llc',
        'inc',
        'corp',
        'corporation',
        'co',
        'company',
    ];

    public static function matches(?string $partnerName, ?string $companyName, float $minPercent = 88.0): bool
    {
        $a = self::normalize($partnerName);
        $b = self::normalize($companyName);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= $minPercent;
    }

    public static function normalize(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }

        $pattern = '/\b('.implode('|', self::STRIP_TOKENS).')\b/u';
        $name = preg_replace($pattern, ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', '', $name) ?? '';

        return $name;
    }
}
