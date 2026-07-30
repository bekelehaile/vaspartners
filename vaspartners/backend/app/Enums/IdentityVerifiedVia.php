<?php

namespace App\Enums;

enum IdentityVerifiedVia: string
{
    case Fayda = 'fayda';
    case Crm = 'crm';

    public function label(): string
    {
        return match ($this) {
            self::Fayda => 'Fayda (National ID)',
            self::Crm => 'CRM (Ethio telecom)',
        };
    }
}
