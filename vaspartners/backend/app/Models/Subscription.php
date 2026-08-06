<?php

namespace App\Models;

use App\Enums\RenewalInterval;
use App\Enums\ServiceOperationalStatus;
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
        'operational_status',
        'operational_status_updated_at',
        'renewal_interval',
        'started_at',
        'contract_signed_at',
        'renewal_years',
        'renewal_date',
        'automatic_renewal',
        'vas_license_expires_at',
        'current_period_start',
        'current_period_end',
        'next_renewal_due_at',
        'activated_by_ticket_id',
        'terminated_by_ticket_id',
        'terminated_at',
        'closed_at',
        'legacy_mvas_id',
        'legacy_mvas_service_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'operational_status' => ServiceOperationalStatus::class,
            'renewal_interval' => RenewalInterval::class,
            'started_at' => 'datetime',
            'contract_signed_at' => 'date',
            'renewal_years' => 'integer',
            'renewal_date' => 'date',
            'automatic_renewal' => 'boolean',
            'vas_license_expires_at' => 'date',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_renewal_due_at' => 'datetime',
            'terminated_at' => 'datetime',
            'closed_at' => 'datetime',
            'operational_status_updated_at' => 'datetime',
        ];
    }

    public function requiresVasLicenseExpiry(): bool
    {
        $this->loadMissing('service');

        return (bool) $this->service?->isPremium();
    }

    /**
     * Contract follow-up fields required before status can move to Closed.
     *
     * @return list<string> Missing field labels
     */
    public function missingContractCloseFields(): array
    {
        $missing = [];

        if ($this->contract_signed_at === null) {
            $missing[] = 'Contract signing date';
        }

        if ($this->renewal_date === null) {
            $missing[] = 'Renewal date';
        }

        if ($this->requiresVasLicenseExpiry() && $this->vas_license_expires_at === null) {
            $missing[] = 'VAS license expiry date';
        }

        return $missing;
    }

    public function isReadyToClose(): bool
    {
        return $this->missingContractCloseFields() === [];
    }

    /**
     * Build renewal date by adding N years to the contract signing date.
     * Uses no-overflow so 29 Feb + 1 year becomes 28 Feb in non-leap years.
     */
    public static function composeRenewalDate(
        mixed $contractSignedAt,
        mixed $years,
    ): ?\Illuminate\Support\Carbon {
        if (! filled($contractSignedAt) || ! filled($years)) {
            return null;
        }

        $n = (int) $years;
        if ($n < 1 || $n > 20) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($contractSignedAt)
                ->startOfDay()
                ->addYearsNoOverflow($n);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Years between signing date and stored renewal date (for form defaults).
     */
    public static function renewalYearsBetween(mixed $contractSignedAt, mixed $renewalDate): ?int
    {
        if (! filled($contractSignedAt) || ! filled($renewalDate)) {
            return null;
        }

        try {
            $from = \Illuminate\Support\Carbon::parse($contractSignedAt)->startOfDay();
            $to = \Illuminate\Support\Carbon::parse($renewalDate)->startOfDay();
            if ($to->lessThan($from)) {
                return null;
            }

            $years = (int) $from->diffInYears($to);
            if ($years < 1) {
                return 1;
            }

            return min(20, $years);
        } catch (\Throwable) {
            return null;
        }
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

    /** Provisioning / lifecycle events (activate, renew, terminate, uptime). */
    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(SubscriptionProvisioningLog::class)->orderByDesc('id');
    }
}
