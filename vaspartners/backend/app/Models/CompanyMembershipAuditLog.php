<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMembershipAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'membership_id',
        'member_contact_id',
        'action',
        'actor_user_id',
        'actor_contact_id',
        'before',
        'after',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CompanyMembership::class, 'membership_id');
    }

    public function memberContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'member_contact_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'actor_contact_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'permissions_updated' => 'Permissions updated',
            'access_enabled' => 'Access enabled',
            'access_disabled' => 'Access disabled',
            default => str_replace('_', ' ', ucfirst((string) $this->action)),
        };
    }
}
