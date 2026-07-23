<?php

namespace App\Enums;

enum CompanyApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}
