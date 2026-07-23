<?php

namespace App\Models;

use App\Enums\RenewalInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'is_active',
        'is_subscription_based',
        'renewal_interval',
        'renewal_lead_days',
        'renewal_requisition_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_subscription_based' => 'boolean',
            'renewal_interval' => RenewalInterval::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function renewalRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'renewal_requisition_id');
    }

    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(Requisition::class);
    }

    public function documentMatrix(): HasMany
    {
        return $this->hasMany(ServiceRequisitionDocument::class);
    }

    public function finalApprovers(): HasMany
    {
        return $this->hasMany(ServiceFinalApprover::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
