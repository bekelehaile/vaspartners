<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'action',
        'approval_status',
        'is_active',
        'tin_validated',
        'actor_user_id',
        'actor_contact_id',
        'note',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tin_validated' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
            'approved' => 'Profile approved',
            'rejected' => 'Profile rejected',
            'tin_validated' => 'TIN NUMBER validated',
            'tin_cleared' => 'TIN validation cleared',
            'activated' => 'Marked active',
            'deactivated' => 'Marked inactive',
            'conditions_updated' => 'Conditions updated',
            default => str_replace('_', ' ', ucfirst((string) $this->action)),
        };
    }
}
