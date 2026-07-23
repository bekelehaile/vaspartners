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

/**
 * Partner identity from Fayda on sign-in; company details completed afterwards.
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'public_id',
        'sub',
        'name',
        'company_name',
        'company_tin',
        'company_phone',
        'company_email',
        'company_address',
        'phone_number',
        'email',
        'gender',
        'nationality',
        'identification_type',
        'identification_number',
        'birthdate',
        'picture',
        'address',
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
        return $this->profile_completed_at !== null
            && filled($this->company_name)
            && filled($this->company_tin)
            && filled($this->company_phone)
            && filled($this->company_email)
            && filled($this->company_address);
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
