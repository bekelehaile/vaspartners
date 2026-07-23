<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'phone',
        'email',
        'address',
        'is_active',
        'created_by_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'created_by_customer_id');
    }

    /** All linked partners (owner + members). */
    public function members(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(Customer::class)->where('company_role', CompanyRole::Owner->value);
    }

    public function nonOwnerMembers(): HasMany
    {
        return $this->hasMany(Customer::class)->where('company_role', CompanyRole::Member->value);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(CompanyChangeRequest::class);
    }

    public function hasOwner(): bool
    {
        return $this->owner()->exists();
    }

    public function memberCount(): int
    {
        return $this->members()->count();
    }
}
