<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProvisioningStepStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Waiting => 'Waiting',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Skipped => 'Skipped',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Waiting => 'warning',
            self::Completed => 'success',
            self::Rejected => 'danger',
            self::Skipped => 'gray',
        };
    }
}
