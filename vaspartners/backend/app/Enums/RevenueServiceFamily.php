<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Canonical finance product lines (Excel sheets used for monthly revenue).
 */
enum RevenueServiceFamily: string
{
    case ApiWithMa = 'api_with_ma';
    case SmsMoPremium = 'sms_mo_premium';
    case PremiumSmsMt = 'premium_sms_mt';
    case CrbtPartners = 'crbt_partners';

    public function label(): string
    {
        return match ($this) {
            self::ApiWithMa => 'API with MA',
            self::SmsMoPremium => 'SMS-MO Premium',
            self::PremiumSmsMt => 'Premium SMS MT',
            self::CrbtPartners => 'CRBT Partners',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ApiWithMa => 'info',
            self::SmsMoPremium => 'success',
            self::PremiumSmsMt => 'warning',
            self::CrbtPartners => 'gray',
        };
    }

    /**
     * Map a workbook sheet name to a product family (null = ignore sheet).
     */
    public static function fromSheetName(?string $sheetName): ?self
    {
        $name = Str::lower(trim((string) $sheetName));
        $name = preg_replace('/\s*\(\d+\)\s*$/', '', $name) ?? $name;
        $name = trim(str_replace('_', ' ', $name));

        if ($name === '') {
            return null;
        }

        if (str_contains($name, 'crbt')) {
            return self::CrbtPartners;
        }

        // Premium SMS MT (before generic MO / premium checks)
        if (str_contains($name, 'premium sms mt')
            || (str_contains($name, 'sms') && str_contains($name, ' mt'))
            || (preg_match('/\bmt\b/', $name) && str_contains($name, 'premium') && ! str_contains($name, 'mo'))) {
            return self::PremiumSmsMt;
        }

        if (str_contains($name, 'sms-mo')
            || str_contains($name, 'sms mo')
            || (str_contains($name, 'mo') && str_contains($name, 'premium'))) {
            return self::SmsMoPremium;
        }

        if (str_contains($name, 'api with ma')
            || (str_contains($name, 'api') && str_contains($name, 'ma'))) {
            return self::ApiWithMa;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
