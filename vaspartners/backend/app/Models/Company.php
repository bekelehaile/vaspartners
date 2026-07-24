<?php

namespace App\Models;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
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
        'license_number',
        'phone',
        'email',
        'address',
        'is_active',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_note',
        'created_by_customer_id',
        'legacy_mvas_client_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approval_status' => CompanyApprovalStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): bool {
            return false;
        });
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
        return $this->belongsTo(Customer::class, 'created_by_customer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /** All linked partners (owner + members). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'company_memberships')
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
        return $this->belongsToMany(Customer::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->wherePivot('role', CompanyRole::Owner->value)
            ->withTimestamps();
    }

    public function nonOwnerMembers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->wherePivot('role', CompanyRole::Member->value)
            ->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'company_memberships')
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
     * - tickets owned by migrated customer with same legacy_mvas_client_id
     */
    public function serviceRequests(): Builder
    {
        $companyId = (int) $this->getKey();
        $legacyClientId = $this->legacy_mvas_client_id;

        return Ticket::query()
            ->where(function (Builder $query) use ($companyId, $legacyClientId): void {
                $query
                    ->whereHas(
                        'subscription',
                        fn (Builder $q) => $q->where('company_id', $companyId),
                    )
                    ->orWhereHas(
                        'customer.memberships',
                        fn (Builder $q) => $q->where('company_id', $companyId),
                    );

                if ($legacyClientId !== null && $legacyClientId !== '') {
                    $query->orWhereHas(
                        'customer',
                        fn (Builder $q) => $q->where('legacy_mvas_client_id', $legacyClientId),
                    );
                }
            });
    }

    public function hasOwner(): bool
    {
        return $this->memberships()->where('role', CompanyRole::Owner->value)->exists();
    }

    public function ownerCustomer(): ?Customer
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

    public function setTinAttribute(?string $value): void
    {
        $this->attributes['tin'] = self::normalizeIdentityCode($value) ?? '';
    }

    public function setLicenseNumberAttribute(?string $value): void
    {
        $this->attributes['license_number'] = self::normalizeIdentityCode($value);
    }
}
