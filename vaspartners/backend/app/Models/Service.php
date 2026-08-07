<?php

namespace App\Models;

use App\Enums\RenewalInterval;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'image',
        'type',
        'is_active',
        'has_monthly_revenue',
        'is_subscription_based',
        'renewal_interval',
        'renewal_lead_days',
        'renewal_requisition_id',
        'sort_order',
    ];

    protected $appends = [
        'image_url',
    ];

    protected $attributes = [
        'is_active' => true,
        'has_monthly_revenue' => false,
        'is_subscription_based' => true,
        'renewal_interval' => 'yearly',
        'renewal_lead_days' => 30,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'has_monthly_revenue' => 'boolean',
            'is_subscription_based' => 'boolean',
            'renewal_interval' => RenewalInterval::class,
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! filled($this->image)) {
            return null;
        }

        return Storage::disk('public')->url($this->image);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Operational groups (Group 1 / Group 2) — one or both. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    /**
     * Keep category_id aligned with the first assigned group (sort order).
     *
     * @param  list<int|string>  $categoryIds
     */
    public function syncGroups(array $categoryIds): void
    {
        $ids = collect($categoryIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $validIds = Category::query()
            ->operationalGroups()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->categories()->sync($validIds);

        $primary = $validIds[0] ?? null;
        if ($primary && (int) $this->category_id !== (int) $primary) {
            $this->forceFill(['category_id' => $primary])->saveQuietly();
        }
    }

    public function renewalRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'renewal_requisition_id');
    }

    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(Requisition::class, 'service_requisition');
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Open / in-progress tickets that should block catalog delete. */
    public function activeTickets(): HasMany
    {
        return $this->tickets()->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::InProgress->value,
        ]);
    }

    public function hasActiveRequests(): bool
    {
        return $this->activeTickets()->exists();
    }

    public function scopeWithoutActiveRequests(Builder $query): Builder
    {
        return $query->whereDoesntHave('tickets', fn (Builder $q) => $q->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::InProgress->value,
        ]));
    }

    /**
     * Premium VAS (SMS/Voice/USSD/MT Premium). Non-premium and other services return false.
     */
    public function isPremium(): bool
    {
        $haystack = strtolower(trim(($this->slug ?? '').' '.($this->name ?? '')));

        if ($haystack === '') {
            return false;
        }

        if (
            str_contains($haystack, 'non-premium')
            || str_contains($haystack, 'non_premium')
            || str_contains($haystack, 'non premium')
        ) {
            return false;
        }

        return str_contains($haystack, 'premium');
    }

    public function scopeWithMonthlyRevenue(Builder $query): Builder
    {
        return $query->where('has_monthly_revenue', true);
    }
}
