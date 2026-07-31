<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements CanResetPasswordContract, FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use CanResetPassword, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'must_change_password',
        'manager_id',
        'is_management',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_management' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function recordLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    public function setPhoneAttribute(mixed $value): void
    {
        $this->attributes['phone'] = PhoneNumber::normalizeNullable($value);
    }

    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = EmailAddress::normalize($value);
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

    /**
     * Super admin and users flagged for ticket supervision see all revenue data.
     * Everyone else stays scoped to partners they own.
     */
    public function canAccessAllRevenue(): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return (bool) $this->is_management;
    }

    /**
     * Operational group IDs this staff member is scoped to.
     * Empty collection = no group restriction from category_user.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function scopedCategoryIds()
    {
        return $this->categories()
            ->whereIn('categories.key', [Category::KEY_GROUP_1, Category::KEY_GROUP_2])
            ->pluck('categories.id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function hasCategoryScope(): bool
    {
        return $this->scopedCategoryIds()->isNotEmpty();
    }

    /**
     * Active staff eligible to handle a ticket in the given group
     * (excludes users marked for ticket supervision alerts).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function assignableManagersForCategory(?int $categoryId)
    {
        return self::query()
            ->where('is_active', true)
            ->where('is_management', false)
            ->when($categoryId, function ($q) use ($categoryId) {
                $q->where(function ($inner) use ($categoryId) {
                    $inner->whereDoesntHave('categories')
                        ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
                });
            })
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * Active user with any role can open admin; menu follows role permissions.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->roles()->exists();
    }

    /**
     * Unused by admin OTP reset flow; kept for Laravel CanResetPassword contract.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        Log::info('Password reset notification skipped — admin uses OTP flow', [
            'user_id' => $this->id,
        ]);
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

    /** Individual company SMS (events / ad-hoc). Super admin always allowed. */
    public function canSendCompanySms(): bool
    {
        return $this->hasRole('super_admin') || $this->can('SendSms:Company');
    }

    /** Bulk / filtered company SMS. Super admin always allowed. */
    public function canBulkSendCompanySms(): bool
    {
        return $this->hasRole('super_admin') || $this->can('SendSmsAny:Company');
    }

    /** Individual ticket SMS to the partner contact. Super admin always allowed. */
    public function canSendTicketSms(): bool
    {
        return $this->hasRole('super_admin') || $this->can('SendSms:Ticket');
    }

    /** Bulk / filtered ticket SMS. Super admin always allowed. */
    public function canBulkSendTicketSms(): bool
    {
        return $this->hasRole('super_admin') || $this->can('SendSmsAny:Ticket');
    }
}
