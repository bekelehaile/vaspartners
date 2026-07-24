<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'On progress',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
            self::Rejected => 'Rejected',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed], true);
    }

    /** Contact may change documents / request details only while open or rejected. */
    public function allowsContactEdits(): bool
    {
        return in_array($this, [self::Open, self::Rejected], true);
    }

    /** Handled by admin (in progress / approved / closed) — contact cannot mutate attachments. */
    public function locksContactDocuments(): bool
    {
        return ! $this->allowsContactEdits();
    }

    /** Messaging stays open during review; closed after approval or close. */
    public function locksContactChat(): bool
    {
        return in_array($this, [self::Completed, self::Closed], true);
    }
}
