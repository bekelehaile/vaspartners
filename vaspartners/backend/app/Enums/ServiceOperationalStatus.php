<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Staff-reported service uptime until an external probe feeds this field.
 */
enum ServiceOperationalStatus: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Up => 'Up',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Degraded => 'warning',
            self::Down => 'danger',
            self::Unknown => 'gray',
        };
    }

    public static function tryLabel(mixed $state): string
    {
        if ($state instanceof self) {
            return $state->label();
        }

        return self::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    public static function tryColor(mixed $state): string
    {
        if ($state instanceof self) {
            return $state->getColor();
        }

        return self::tryFrom((string) $state)?->getColor() ?? 'gray';
    }
}
