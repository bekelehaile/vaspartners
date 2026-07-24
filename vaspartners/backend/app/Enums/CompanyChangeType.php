<?php

namespace App\Enums;

enum CompanyChangeType: string
{
    case Attach = 'attach';
    case Detach = 'detach';
    case TransferOwnership = 'transfer_ownership';

    public function label(): string
    {
        return match ($this) {
            self::Attach => 'Membership join',
            self::Detach => 'Leave company',
            self::TransferOwnership => 'Ownership transfer',
        };
    }
}
