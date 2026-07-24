<?php

namespace App\Enums;

enum DocumentReviewStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
        };
    }
}
