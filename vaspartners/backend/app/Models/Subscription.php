<?php

namespace App\Models;

use App\Enums\RenewalInterval;
use App\Enums\SubscriptionStatus;
use App\Support\TimestampPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'contact_id',
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
        'legacy_mvas_id',
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

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription): void {
            if (filled($subscription->public_id)) {
                return;
            }

            $subscription->public_id = TimestampPublicId::generate(
                $subscription->started_at ?? now(),
                fn (string $id): bool => static::withTrashed()->where('public_id', $id)->exists(),
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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

    /** Partner/staff chat across all tickets on this subscription. */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(TicketComment::class, Ticket::class);
    }

    /** Contact document uploads across all tickets on this subscription. */
    public function documents(): HasManyThrough
    {
        return $this->hasManyThrough(TicketDocument::class, Ticket::class);
    }

    /** Ticket status change trail for this subscription. */
    public function statusHistories(): HasManyThrough
    {
        return $this->hasManyThrough(TicketStatusHistory::class, Ticket::class);
    }
}
