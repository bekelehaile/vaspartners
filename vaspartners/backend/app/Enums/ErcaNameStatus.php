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
    case NameMissing = 'name_missing';
    case PartnerEntered = 'partner_entered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'Not checked',
            self::Matched => 'Yes',
            self::MismatchPending => 'No',
            self::AcceptedLegal => 'Partner accepted ERCA legal name',
            self::KeptBoth => 'Partner kept entered name + legal name',
            self::NotFound => 'TIN number not found in ERCA',
            self::NameMissing => 'TIN number found — legal name missing',
            self::PartnerEntered => 'Partner entered company name (ERCA had no name)',
            self::Failed => 'ERCA check failed',
        };
    }

    public function needsPartnerConsent(): bool
    {
        return $this === self::MismatchPending;
    }

    /**
     * ERCA found the TIN number but returned no legal name — partner must enter one.
     */
    public function needsPartnerNameEntry(): bool
    {
        return $this === self::NameMissing;
    }

    /**
     * Partner cannot change company name or TIN number once ERCA name is aligned.
     */
    public function locksPartnerIdentity(): bool
    {
        return in_array($this, [self::Matched, self::AcceptedLegal], true);
    }

    public function isResolved(): bool
    {
        return in_array($this, [
            self::Matched,
            self::AcceptedLegal,
            self::KeptBoth,
            self::PartnerEntered,
        ], true);
    }
}
