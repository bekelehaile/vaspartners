<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProvisioningStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case GoLive = 'go_live';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::GoLive => 'Go live',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InProgress => 'warning',
            self::Blocked => 'danger',
            self::GoLive => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
