<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProvisioningWorkflow: string implements HasLabel
{
    case PremiumFull = 'premium_full';

    public function label(): string
    {
        return match ($this) {
            self::PremiumFull => 'Normal Service (API | MT | USSD Premium)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
