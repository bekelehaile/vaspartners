<?php

namespace App\Enums;

enum RevenueImportRowStatus: string
{
    case Matched = 'matched';
    case MissingPartner = 'missing_partner';
    case MissingPhone = 'missing_phone';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Ready',
            self::MissingPartner => 'Missing partner',
            self::MissingPhone => 'Missing phone',
            self::Invalid => 'Invalid',
            self::Duplicate => 'Duplicate',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::MissingPartner => 'warning',
            self::MissingPhone => 'warning',
            self::Invalid, self::Duplicate => 'danger',
        };
    }
}
