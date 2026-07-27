<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
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
 * A contact may own and/or join many companies via company_memberships.
 * current_company_id is the active portal/tenant context.
 */
class Contact extends Authenticatable
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
        'company_phone',
        'company_email',
        'company_address',
        'current_company_id',
        'legacy_mvas_id',
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
        static::saving(function (Contact $contact): void {
            if (array_key_exists('phone_number', $contact->getAttributes())
                || $contact->isDirty('phone_number')) {
                $contact->attributes['phone_number'] = PhoneNumber::normalizeNullable(
                    $contact->attributes['phone_number'] ?? null,
                );
            }

            if (array_key_exists('company_phone', $contact->getAttributes())
                || $contact->isDirty('company_phone')) {
                $contact->attributes['company_phone'] = PhoneNumber::normalizeNullable(
                    $contact->attributes['company_phone'] ?? null,
                );
            }

            if (array_key_exists('email', $contact->getAttributes())
                || $contact->isDirty('email')) {
                $contact->attributes['email'] = EmailAddress::normalize(
                    $contact->attributes['email'] ?? null,
                );
            }

            if (array_key_exists('company_email', $contact->getAttributes())
                || $contact->isDirty('company_email')) {
                $contact->attributes['company_email'] = EmailAddress::normalize(
                    $contact->attributes['company_email'] ?? null,
                );
            }

            if ($contact->allowFaydaSync) {
                return;
            }

            $dirtyFayda = array_values(array_intersect(
                array_keys($contact->getDirty()),
                self::FAYDA_ATTRIBUTES
            ));

            if ($dirtyFayda === []) {
                return;
            }

            foreach ($dirtyFayda as $attribute) {
                $contact->setAttribute($attribute, $contact->getOriginal($attribute));
            }
        });
    }

    public function setPhoneNumberAttribute(mixed $value): void
    {
        $this->attributes['phone_number'] = PhoneNumber::normalizeNullable($value);
    }

    public function setCompanyPhoneAttribute(mixed $value): void
    {
        $this->attributes['company_phone'] = PhoneNumber::normalizeNullable($value);
    }

    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = EmailAddress::normalize($value);
    }

    public function setCompanyEmailAttribute(mixed $value): void
    {
        $this->attributes['company_email'] = EmailAddress::normalize($value);
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

    /**
     * Admin / Filament updates — may correct Fayda identity fields (next Fayda login can overwrite them).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateFromAdmin(array $attributes): void
    {
        $allowed = [
            ...self::FAYDA_ATTRIBUTES,
            'is_active',
            'is_banned',
            'legacy_mvas_id',
            'company_name',
            'company_tin',
            'company_phone',
            'company_email',
            'company_address',
            'current_company_id',
            'profile_completed_at',
        ];

        $payload = array_intersect_key($attributes, array_flip($allowed));
        // Fayda subject is the SSO key — never change via admin form.
        unset($payload['sub']);

        if ($payload === []) {
            return;
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
            && filled($this->company_tin);
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
            'contact_id',
            'id',
            'id',
            'service_id',
        );
    }

    /**
     * Contacts with no memberships, tickets, subscriptions, or change requests,
     * and no current company context.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query
            ->whereNull('current_company_id')
            ->whereDoesntHave('memberships')
            ->whereDoesntHave('tickets')
            ->whereDoesntHave('subscriptions')
            ->whereDoesntHave('companyChangeRequests');
    }

    /**
     * Safe for admin bulk soft-delete: no company links or operational data.
     */
    public function isSafeToSoftDelete(): bool
    {
        if ($this->current_company_id) {
            return false;
        }

        if ($this->relationLoaded('memberships') && $this->memberships->isNotEmpty()) {
            return false;
        }
        if ($this->relationLoaded('tickets') && $this->tickets->isNotEmpty()) {
            return false;
        }
        if ($this->relationLoaded('subscriptions') && $this->subscriptions->isNotEmpty()) {
            return false;
        }
        if ($this->relationLoaded('companyChangeRequests') && $this->companyChangeRequests->isNotEmpty()) {
            return false;
        }

        return ! $this->memberships()->exists()
            && ! $this->tickets()->exists()
            && ! $this->subscriptions()->exists()
            && ! $this->companyChangeRequests()->exists();
    }
}
