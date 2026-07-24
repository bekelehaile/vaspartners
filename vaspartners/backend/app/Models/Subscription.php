<?php

namespace App\Models;

use App\Enums\RenewalInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id',
        'customer_id',
        'company_id',
        'service_id',
        'status',
        'renewal_interval',
        'started_at',
        'current_period_start',
        'current_period_end',
        'next_renewal_due_at',
        'activated_by_ticket_id',
        'terminated_by_ticket_id',
        'terminated_at',
        'legacy_mvas_client_id',
        'legacy_mvas_service_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'renewal_interval' => RenewalInterval::class,
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_renewal_due_at' => 'datetime',
            'terminated_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Owning organisation — subscriptions transfer with company ownership/membership. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function activatedByTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'activated_by_ticket_id');
    }

    public function terminatedByTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'terminated_by_ticket_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
