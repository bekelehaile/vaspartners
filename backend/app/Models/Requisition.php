<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'description',
        'is_active',
        'sort_order',
        'creates_subscription',
        'requires_active_subscription',
        'renews_subscription',
        'terminates_subscription',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'creates_subscription' => 'boolean',
            'requires_active_subscription' => 'boolean',
            'renews_subscription' => 'boolean',
            'terminates_subscription' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(ServiceRequisitionDocument::class);
    }

    public function finalApprovers(): HasMany
    {
        return $this->hasMany(ServiceFinalApprover::class);
    }
}
