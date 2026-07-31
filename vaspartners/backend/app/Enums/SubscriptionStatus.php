<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PendingRenewal = 'pending_renewal';
    case Grace = 'grace';
    case Expired = 'expired';
    /** Partner consented to quit (termination request closed) — system marks deactive. */
    case Deactive = 'deactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PendingRenewal => 'Pending renewal',
            self::Grace => 'Grace period',
            self::Expired => 'Expired',
            self::Deactive => 'Deactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingRenewal, self::Grace => 'warning',
            self::Expired, self::Deactive => 'danger',
        };
    }

    public function isAlive(): bool
    {
        return in_array($this, [self::Active, self::PendingRenewal, self::Grace], true);
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
            return $state->color();
        }

        return self::tryFrom((string) $state)?->color() ?? 'gray';
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $case) => [$case->value => $case->label()]
        )->all();
    }
}
