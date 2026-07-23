<?php

namespace App\Enums;

enum CompanyChangeType: string
{
    case Attach = 'attach';
    case Detach = 'detach';

    public function label(): string
    {
        return match ($this) {
            self::Attach => 'Attach to company',
            self::Detach => 'Detach from company',
        };
    }
}
