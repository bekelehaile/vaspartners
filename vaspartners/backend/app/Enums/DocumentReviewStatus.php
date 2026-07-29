<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentReviewStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed => 'Reviewed',
            self::Failed => 'Failed',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Pending => 'warning',
        };
    }
}
