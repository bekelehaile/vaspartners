<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'public_id',
        'fayda_sub',
        'company_name',
        'name',
        'email',
        'phone',
        'gender',
        'nationality',
        'birthdate',
        'address',
        'picture',
        'is_active',
        'is_banned',
        'profile_completed_at',
    ];

    protected $hidden = [
        'picture',
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
