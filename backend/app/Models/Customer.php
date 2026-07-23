<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * Partner identity from Fayda on sign-in; company details completed afterwards.
 * Fayda identity fields are immutable outside Fayda sync.
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
        'company_phone',
        'company_email',
        'company_address',
        'company_id',
        'company_role',
        'is_active',
        'is_banned',
        'profile_completed_at',
    ];

    protected $hidden = [
        'picture',
    ];

    protected $appends = [
        'profile_completed',
    ];

    /** Allow Fayda sync to write identity fields once per save. */
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

            // Strip accidental mutations instead of hard-failing mass updates from tools.
            foreach ($dirtyFayda as $attribute) {
                $customer->setAttribute($attribute, $customer->getOriginal($attribute));
            }
        });
    }

    /**
     * Apply identity fields from Fayda / eSignet userinfo only.
     *
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

    public function getProfileCompletedAttribute(): bool
    {
        if ($this->company_id) {
            return $this->profile_completed_at !== null
                && filled($this->company_name)
                && filled($this->company_tin);
        }

        return $this->profile_completed_at !== null
            && filled($this->company_name)
            && filled($this->company_tin)
            && filled($this->company_phone)
            && filled($this->company_email)
            && filled($this->company_address);
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /** Services reached via subscriptions (active catalog history for this partner). */
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
