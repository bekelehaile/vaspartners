<?php

namespace App\Models;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use App\Support\TinNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'legal_name',
        'tin',
        'tin_validated',
        'erca_name_status',
        'erca_tin_verified',
        'erca_verified_at',
        'erca_last_checked_at',
        'erca_next_check_at',
        'erca_last_error',
        'phone',
        'email',
        'address',
        'is_active',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_note',
        'created_by_contact_id',
        'legacy_mvas_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tin_validated' => 'boolean',
            'erca_tin_verified' => 'boolean',
            'approval_status' => CompanyApprovalStatus::class,
            'erca_name_status' => \App\Enums\ErcaNameStatus::class,
            'approved_at' => 'datetime',
            'erca_verified_at' => 'datetime',
            'erca_last_checked_at' => 'datetime',
            'erca_next_check_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if (array_key_exists('phone', $company->getAttributes()) || $company->isDirty('phone')) {
                $company->attributes['phone'] = PhoneNumber::normalizeNullable(
                    $company->attributes['phone'] ?? null,
                );
            }

            if (array_key_exists('email', $company->getAttributes()) || $company->isDirty('email')) {
                $company->attributes['email'] = EmailAddress::normalize(
                    $company->attributes['email'] ?? null,
                );
            }

            // Changing TIN on an existing company clears validation (TIN is the service gate).
            // (Skip on create so ERCA-consent create can set verified/approved in the same insert.)
            if ($company->exists && $company->isDirty('tin')) {
                $company->tin_validated = false;
                $company->legal_name = null;
                $company->erca_tin_verified = false;
                $company->erca_verified_at = null;
                $company->erca_name_status = \App\Enums\ErcaNameStatus::Unchecked->value;
                $company->erca_last_error = null;
                $company->erca_next_check_at = null;
            }
        });
    }

    public function needsErcaNameConsent(): bool
    {
        $status = $this->erca_name_status instanceof \App\Enums\ErcaNameStatus
            ? $this->erca_name_status
            : \App\Enums\ErcaNameStatus::tryFrom((string) $this->erca_name_status);

        return $status?->needsPartnerConsent() === true;
    }

    /**
     * ERCA found the TIN number but returned no legal name — partner must enter a company name.
     */
    public function needsErcaNameEntry(): bool
    {
        $status = $this->erca_name_status instanceof \App\Enums\ErcaNameStatus
            ? $this->erca_name_status
            : \App\Enums\ErcaNameStatus::tryFrom((string) ($this->erca_name_status ?: ''));

        return $status?->needsPartnerNameEntry() === true;
    }

    /**
     * ERCA-matched (or partner-accepted legal name): name + TIN number are frozen for partners.
     */
    public function isErcaIdentityLocked(): bool
    {
        $status = $this->erca_name_status instanceof \App\Enums\ErcaNameStatus
            ? $this->erca_name_status
            : \App\Enums\ErcaNameStatus::tryFrom((string) ($this->erca_name_status ?: ''));

        return $status?->locksPartnerIdentity() === true
            && (bool) $this->erca_tin_verified;
    }

    public function ercaDisplayLegalName(): ?string
    {
        $name = trim((string) ($this->legal_name ?: ''));

        return $name !== '' ? $name : null;
    }

    public function setPhoneAttribute(mixed $value): void
    {
        $this->attributes['phone'] = PhoneNumber::normalizeNullable($value);
    }

    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = EmailAddress::normalize($value);
    }

    public function setTinAttribute(?string $value): void
    {
        $digits = TinNumber::normalize($value);
        if (TinNumber::isValid($digits)) {
            $this->attributes['tin'] = $digits;

            return;
        }

        // Legacy / placeholder TINs (e.g. migrated MVAS ids) until partner updates.
        $this->attributes['tin'] = self::normalizeIdentityCode($value) ?? '';
    }

    public function hasValidEthiopianTin(): bool
    {
        return TinNumber::isValid($this->tin);
    }

    /**
     * TIN number is verified when ERCA found it (name mismatch may still need consent).
     * Admin-only tin_validated flags are not trusted.
     */
    public function isTinValidated(): bool
    {
        return $this->hasValidEthiopianTin() && (bool) $this->erca_tin_verified;
    }

    /**
     * Entered name aligned with ERCA (or partner already consented).
     */
    public function isErcaNameResolved(): bool
    {
        $status = $this->erca_name_status instanceof \App\Enums\ErcaNameStatus
            ? $this->erca_name_status
            : \App\Enums\ErcaNameStatus::tryFrom((string) ($this->erca_name_status ?: ''));

        return $status?->isResolved() === true;
    }

    /**
     * Company is usable when TIN number is ERCA-verified, name is settled, and Active is on.
     */
    public function isApproved(): bool
    {
        return $this->isTinValidated()
            && $this->isErcaNameResolved()
            && (bool) $this->is_active;
    }

    /**
     * Do not permanently delete companies that have an owner, a valid approved TIN number,
     * and at least one subscription.
     */
    public function isForcePurgeProtected(): bool
    {
        if (! $this->hasOwner() || ! $this->isTinValidated()) {
            return false;
        }

        if (array_key_exists('subscriptions_count', $this->attributes)) {
            return (int) $this->attributes['subscriptions_count'] > 0;
        }

        if (isset($this->subscriptions_count)) {
            return (int) $this->subscriptions_count > 0;
        }

        return $this->subscriptions()->exists();
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'created_by_contact_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(CompanyStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /** All linked partners (owner + members). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function ownerMembership(): HasOne
    {
        return $this->hasOne(CompanyMembership::class)
            ->where('role', CompanyRole::Owner->value);
    }

    public function owner(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->wherePivot('role', CompanyRole::Owner->value)
            ->withTimestamps();
    }

    public function nonOwnerMembers(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->wherePivot('role', CompanyRole::Member->value)
            ->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(CompanyChangeRequest::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Service requests (tickets) for this company:
     * - tickets on company subscriptions
     * - tickets owned by current members
     * - tickets owned by migrated contact with same legacy_mvas_id
     */
    public function serviceRequests(): Builder
    {
        $companyId = (int) $this->getKey();
        $legacyMvasId = $this->legacy_mvas_id;

        return Ticket::query()
            ->where(function (Builder $query) use ($companyId, $legacyMvasId): void {
                $query
                    ->whereHas(
                        'subscription',
                        fn (Builder $q) => $q->where('company_id', $companyId),
                    )
                    ->orWhereHas(
                        'contact.memberships',
                        fn (Builder $q) => $q->where('company_id', $companyId),
                    );

                if ($legacyMvasId !== null && $legacyMvasId !== '') {
                    $query->orWhereHas(
                        'contact',
                        fn (Builder $q) => $q->where('legacy_mvas_id', $legacyMvasId),
                    );
                }
            });
    }

    public function hasOwner(): bool
    {
        if (array_key_exists('has_owner_flag', $this->attributes)) {
            return (bool) $this->attributes['has_owner_flag'];
        }

        return $this->memberships()->where('role', CompanyRole::Owner->value)->exists();
    }

    /** True when the company has no owner membership (orphan / awaiting claim). */
    public function isOwnerless(): bool
    {
        return ! $this->hasOwner();
    }

    public function scopeOwnerless(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'memberships',
            fn (Builder $q) => $q->where('role', CompanyRole::Owner->value),
        );
    }

    /**
     * Valid 10-digit TIN that is not yet ERCA-OK (partner/ERCA still needed).
     */
    public function scopeAwaitingTinApproval(Builder $query): Builder
    {
        $done = [
            \App\Enums\ErcaNameStatus::Matched->value,
            \App\Enums\ErcaNameStatus::AcceptedLegal->value,
            \App\Enums\ErcaNameStatus::KeptBoth->value,
            \App\Enums\ErcaNameStatus::MismatchPending->value,
            \App\Enums\ErcaNameStatus::NameMissing->value,
            \App\Enums\ErcaNameStatus::PartnerEntered->value,
        ];

        return $query
            ->whereNotNull('tin')
            ->where('tin', '!=', '')
            ->whereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) = 10")
            ->where(function (Builder $q) use ($done): void {
                $q->where('erca_tin_verified', false)
                    ->orWhereNull('erca_name_status')
                    ->orWhereNotIn('erca_name_status', $done);
            });
    }

    /**
     * ERCA found the TIN number (name may still be mismatch_pending).
     */
    public function scopeTinApproved(Builder $query): Builder
    {
        return $query
            ->where('erca_tin_verified', true)
            ->whereNotNull('tin')
            ->where('tin', '!=', '')
            ->whereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) = 10");
    }

    /**
     * TIN number verified and name settled (ready for services when also active).
     */
    public function scopeErcaIdentityResolved(Builder $query): Builder
    {
        return $query
            ->tinApproved()
            ->whereIn('erca_name_status', [
                \App\Enums\ErcaNameStatus::Matched->value,
                \App\Enums\ErcaNameStatus::AcceptedLegal->value,
                \App\Enums\ErcaNameStatus::KeptBoth->value,
                \App\Enums\ErcaNameStatus::PartnerEntered->value,
            ]);
    }

    /**
     * Missing TIN or not a valid 10-digit Ethiopian TIN number.
     */
    public function scopeInvalidOrMissingTin(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('tin')
                ->orWhere('tin', '')
                ->orWhereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) <> 10");
        });
    }

    public function scopeErcaNameMismatchPending(Builder $query): Builder
    {
        return $query->where('erca_name_status', \App\Enums\ErcaNameStatus::MismatchPending->value);
    }

    public function ownerContact(): ?Contact
    {
        return $this->owner()->first();
    }

    public function memberCount(): int
    {
        return $this->memberships()->count();
    }

    protected static function normalizeIdentityCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
