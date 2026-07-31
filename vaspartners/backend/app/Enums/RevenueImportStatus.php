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

    /** Short explanation shown next to Status on the import view. */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Import created; matching not finished yet.',
            self::Reviewing => 'Some rows still need attention (unresolved partner, missing phone, or mixed ready/problem rows).',
            self::Ready => 'All usable rows are matched with a phone. You can send SMS.',
            self::Sending => 'Revenue SMS is queued or sending.',
            self::Completed => 'SMS finished for this import; nothing left to send.',
            self::Failed => 'Nothing ready to send — empty file, or every row is invalid/duplicate (no Ready / Unresolved / Missing phone rows). Check Invalid / duplicate, fix the CSV, then rematch.',
        };
    }
}
