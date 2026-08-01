<?php

namespace App\Models;

use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id', 'tt_number', 'legacy_mvas_ticket_id', 'contact_id', 'service_id', 'requisition_id', 'subscription_id',
        'parent_ticket_id', 'category_id', 'priority_id', 'region_id', 'zone_id', 'woreda_id',
        'assigned_to_user_id', 'current_approver_user_id', 'status', 'document_review_status',
        'needs_reverification', 'building', 'location', 'description', 'assigned_at',
        'opened_at', 'in_progress_at', 'escalated_at', 'completed_at', 'rejected_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'document_review_status' => DocumentReviewStatus::class,
            'needs_reverification' => 'boolean',
            'assigned_at' => 'datetime',
            'opened_at' => 'datetime',
            'in_progress_at' => 'datetime',
            'escalated_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected $appends = [
        'documents_locked',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'tt_number';
    }

    /**
     * Resolve by request number, with public_id fallback for older portal links.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        return $this->where(function ($query) use ($value, $field): void {
            $query->where($field, $value);

            if ($field === 'tt_number') {
                $query->orWhere('public_id', $value);
            }
        })->firstOrFail();
    }

    public function contactDocumentsAreLocked(): bool
    {
        return $this->status?->locksContactDocuments() ?? false;
    }

    public function getDocumentsLockedAttribute(): bool
    {
        return $this->contactDocumentsAreLocked();
    }

    /** @var array<string, mixed>|null */
    protected ?array $attachmentStatusCache = null;

    /** @return array<string, mixed> */
    public function attachmentStatus(): array
    {
        return $this->attachmentStatusCache ??= app(\App\Services\TicketWorkflowService::class)->attachmentStatus($this);
    }

    /**
     * Doc-review badge status for UI.
     * Closed/Completed with all required docs must not stay Pending.
     * Active service + legacy incomplete docs must not show Failed.
     */
    public function effectiveDocumentReviewStatus(): ?DocumentReviewStatus
    {
        $attachState = $this->attachmentStatus()['state'] ?? null;
        if ($attachState === 'none_required') {
            return null;
        }

        $stored = $this->document_review_status instanceof DocumentReviewStatus
            ? $this->document_review_status
            : DocumentReviewStatus::tryFrom((string) $this->document_review_status);

        $workflow = app(\App\Services\TicketWorkflowService::class);
        if (
            $stored === DocumentReviewStatus::Failed
            && in_array($this->status, [TicketStatus::Closed, TicketStatus::Completed], true)
            && $workflow->ticketHasAliveService($this)
        ) {
            return DocumentReviewStatus::Passed;
        }

        if (
            $stored === DocumentReviewStatus::Pending
            && $attachState === 'complete'
            && in_array($this->status, [TicketStatus::Closed, TicketStatus::Completed], true)
        ) {
            return DocumentReviewStatus::Passed;
        }

        return $stored;
    }

    public function documentReviewLabel(): string
    {
        if (($this->attachmentStatus()['state'] ?? null) === 'none_required') {
            return 'Not needed';
        }

        return $this->effectiveDocumentReviewStatus()?->label() ?? '—';
    }

    public function documentReviewColor(): string
    {
        if (($this->attachmentStatus()['state'] ?? null) === 'none_required') {
            return 'gray';
        }

        return $this->effectiveDocumentReviewStatus()?->getColor() ?? 'gray';
    }

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(Requisition::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }

    /**
     * Company this request serves (subscription company, else contact current company).
     */
    public function serviceCompany(): ?Company
    {
        $this->loadMissing(['subscription.company', 'contact.company']);

        return $this->subscription?->company ?? $this->contact?->company;
    }

    public function parentTicket(): BelongsTo { return $this->belongsTo(self::class, 'parent_ticket_id'); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function priority(): BelongsTo { return $this->belongsTo(Priority::class); }
    public function region(): BelongsTo { return $this->belongsTo(Region::class); }
    public function zone(): BelongsTo { return $this->belongsTo(Zone::class); }
    public function woreda(): BelongsTo { return $this->belongsTo(Woreda::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function currentApprover(): BelongsTo { return $this->belongsTo(User::class, 'current_approver_user_id'); }
    public function documents(): HasMany { return $this->hasMany(TicketDocument::class); }
    public function comments(): HasMany { return $this->hasMany(TicketComment::class); }
    public function assignments(): HasMany { return $this->hasMany(TicketAssignment::class); }
    public function documentReviews(): HasMany { return $this->hasMany(TicketDocumentReview::class); }
    public function approvalSteps(): HasMany { return $this->hasMany(TicketApprovalStep::class); }
    public function statusHistories(): HasMany { return $this->hasMany(TicketStatusHistory::class); }
}
