<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * Partner identity from Fayda on sign-in; company details completed afterwards.
 * A customer may own and/or join many companies via company_memberships.
 * current_company_id is the active portal/tenant context.
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    /** @var list<string> */
    public const FAYDA_ATTRIBUTES = [
        'sub',
        'name',
        'phone_number',
        'email',
        'gender',
        'nationality',
        'identification_type',
        'identification_number',
        'birthdate',
        'picture',
        'address',
    ];

    protected $fillable = [
        'company_name',
        'company_tin',
        'company_license_number',
        'company_phone',
        'company_email',
        'company_address',
        'current_company_id',
        'is_active',
        'is_banned',
        'profile_completed_at',
    ];

    protected $hidden = [
        'picture',
    ];

    protected $appends = [
        'profile_completed',
        'company_id',
        'company_role',
        'company_membership_active',
    ];

    protected bool $allowFaydaSync = false;

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'birthdate' => 'date',
            'is_active' => 'boolean',
            'is_banned' => 'boolean',
            'profile_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->allowFaydaSync) {
                return;
            }

            $dirtyFayda = array_values(array_intersect(
                array_keys($customer->getDirty()),
                self::FAYDA_ATTRIBUTES
            ));

            if ($dirtyFayda === []) {
                return;
            }

            foreach ($dirtyFayda as $attribute) {
                $customer->setAttribute($attribute, $customer->getOriginal($attribute));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function syncFromFayda(array $attributes): void
    {
        $payload = array_intersect_key($attributes, array_flip(self::FAYDA_ATTRIBUTES));

        if ($payload === []) {
            throw new LogicException('No Fayda identity attributes provided.');
        }

        $this->allowFaydaSync = true;

        try {
            $this->forceFill($payload);
            $this->save();
        } finally {
            $this->allowFaydaSync = false;
        }
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Active portal context company id (compat for older company_id usage). */
    public function getCompanyIdAttribute(): ?int
    {
        $id = $this->attributes['current_company_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function getCompanyRoleAttribute(): ?string
    {
        $membership = $this->membershipForCurrentCompany();
        if (! $membership) {
            return null;
        }

        $role = $membership->role;

        return $role instanceof CompanyRole ? $role->value : (string) $role;
    }

    public function getCompanyMembershipActiveAttribute(): ?bool
    {
        if (! $this->current_company_id) {
            return null;
        }

        $membership = $this->membershipForCurrentCompany();

        return $membership ? (bool) $membership->is_active : false;
    }

    public function hasActiveCompanyMembership(): bool
    {
        if (! $this->current_company_id) {
            return false;
        }

        $membership = $this->membershipForCurrentCompany();

        return $membership?->is_active === true;
    }

    public function getProfileCompletedAttribute(): bool
    {
        if (! $this->hasActiveCompanyMembership()) {
            return false;
        }

        $this->loadMissing('company');
        if (! $this->company?->isApproved()) {
            return false;
        }

        return $this->profile_completed_at !== null
            && filled($this->company_name)
            && filled($this->company_tin)
            && filled($this->company_license_number);
    }

    /** Current company (portal tenant context). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function membershipForCurrentCompany(): ?CompanyMembership
    {
        if (! $this->current_company_id) {
            return null;
        }

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('company_id', (int) $this->current_company_id);
        }

        return $this->memberships()
            ->where('company_id', $this->current_company_id)
            ->first();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function companyChangeRequests(): HasMany
    {
        return $this->hasMany(CompanyChangeRequest::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscribedServices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Service::class,
            Subscription::class,
            'customer_id',
            'id',
            'id',
            'service_id',
        );
    }
}
