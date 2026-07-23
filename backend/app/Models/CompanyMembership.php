<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMembership extends Model
{
    protected $fillable = [
        'customer_id',
        'company_id',
        'role',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOwner(): bool
    {
        $role = $this->role instanceof CompanyRole
            ? $this->role
            : CompanyRole::tryFrom((string) $this->role);

        return $role === CompanyRole::Owner;
    }
}
