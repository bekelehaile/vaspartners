<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMembership extends Model
{
    protected $fillable = [
        'contact_id',
        'company_id',
        'role',
        'is_active',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
