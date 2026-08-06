<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SubscriptionProvisioningLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'event',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'ticket_id',
        'note',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'activated' => 'Service activated',
            'renewed' => 'Service renewed',
            'pending_renewal' => 'Renewal window opened',
            'terminated' => 'Service deactivated',
            'closed' => 'Subscription closed (contract follow-up)',
            'contract_details_updated' => 'Contract details updated',
            'operational_status_changed' => 'Uptime status updated',
            default => str_replace('_', ' ', ucfirst((string) $this->event)),
        };
    }
}
