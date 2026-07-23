<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'manager_id',
        'is_management',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_management' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** Who may start impersonation (Filament Impersonate). */
    public function canImpersonate(): bool
    {
        return $this->is_active
            && (method_exists($this, 'hasRole') && $this->hasRole('super_admin'));
    }

    /** Who may be impersonated. */
    public function canBeImpersonated(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Never allow impersonating yourself.
        if (auth()->id() && (int) auth()->id() === (int) $this->id) {
            return false;
        }

        // Soft-deleted users are already blocked by the package by default.
        return true;
    }
}
