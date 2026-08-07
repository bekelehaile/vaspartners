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

    /** Short explanation shown under Status on the import view. */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Import created; matching is not finished yet.',
            self::Reviewing => 'Some partners still need attention before sending.',
            self::Ready => 'All partners are ready. You can send SMS.',
            self::Sending => 'SMS is currently sending.',
            self::Completed => 'Sending is finished for this import.',
            self::Failed => 'Nothing is ready to send. Check invalid or duplicate rows, then rematch.',
        };
    }
}
