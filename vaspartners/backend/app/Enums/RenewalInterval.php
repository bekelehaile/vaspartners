<?php

namespace App\Enums;

enum RenewalInterval: string
{
    case Yearly = 'yearly';
    case BiYearly = 'bi_yearly';

    public function label(): string
    {
        return match ($this) {
            self::Yearly => 'Yearly',
            self::BiYearly => 'Bi-yearly',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Yearly => 12,
            self::BiYearly => 24,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $case) => [$case->value => $case->label()]
        )->all();
    }
}
