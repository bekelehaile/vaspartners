<?php

namespace App\Enums;

enum ErcaNameStatus: string
{
    case Unchecked = 'unchecked';
    case Matched = 'matched';
    case MismatchPending = 'mismatch_pending';
    case AcceptedLegal = 'accepted_legal';
    case KeptBoth = 'kept_both';
    case NotFound = 'not_found';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'Not checked',
            self::Matched => 'Name matches ERCA',
            self::MismatchPending => 'Name mismatch — awaiting consent',
            self::AcceptedLegal => 'Partner accepted ERCA legal name',
            self::KeptBoth => 'Partner kept entered name + legal name',
            self::NotFound => 'TIN not found in ERCA',
            self::Failed => 'ERCA check failed',
        };
    }

    public function needsPartnerConsent(): bool
    {
        return $this === self::MismatchPending;
    }

    public function isResolved(): bool
    {
        return in_array($this, [
            self::Matched,
            self::AcceptedLegal,
            self::KeptBoth,
        ], true);
    }
}
