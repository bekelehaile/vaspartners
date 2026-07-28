<?php

namespace App\Enums;

enum RevenueImportStatus: string
{
    case Draft = 'draft';
    case Reviewing = 'reviewing';
    case Ready = 'ready';
    case Sending = 'sending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Reviewing => 'Needs review',
            self::Ready => 'Ready to send',
            self::Sending => 'Sending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
