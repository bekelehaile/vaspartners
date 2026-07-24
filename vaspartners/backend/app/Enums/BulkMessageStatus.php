<?php

namespace App\Enums;

enum BulkMessageStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Processing => 'Sending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
