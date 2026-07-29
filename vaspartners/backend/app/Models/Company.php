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
        'tin',
        'tin_validated',
        'tin_validated_by_user_id',
        'tin_validated_at',
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
            'approval_status' => CompanyApprovalStatus::class,
            'approved_at' => 'datetime',
            'tin_validated_at' => 'datetime',
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

            // Changing TIN requires admin to re-validate.
            if ($company->isDirty('tin')) {
                $company->tin_validated = false;
                $company->tin_validated_by_user_id = null;
                $company->tin_validated_at = null;
            }
        });
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

    public function isTinValidated(): bool
    {
        return (bool) $this->tin_validated && $this->hasValidEthiopianTin();
    }

    /**
     * Do not permanently delete companies that have an owner, a valid approved TIN,
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

    public function tinValidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tin_validated_by_user_id');
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
     * Partner submitted a 10-digit TIN NUMBER that still needs admin Approve TIN NUMBER.
     */
    public function scopeAwaitingTinApproval(Builder $query): Builder
    {
        return $query
            ->where('tin_validated', false)
            ->whereNotNull('tin')
            ->where('tin', '!=', '')
            ->whereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) = 10");
    }

    public function scopeTinApproved(Builder $query): Builder
    {
        return $query->where('tin_validated', true);
    }

    public function ownerContact(): ?Contact
    {
        return $this->owner()->first();
    }

    public function memberCount(): int
    {
        return $this->memberships()->count();
    }

    public function isApproved(): bool
    {
        $status = $this->approval_status instanceof CompanyApprovalStatus
            ? $this->approval_status
            : CompanyApprovalStatus::tryFrom((string) $this->approval_status);

        return $status?->isApproved() === true && $this->is_active;
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
