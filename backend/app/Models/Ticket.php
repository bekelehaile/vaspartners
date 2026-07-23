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
        'public_id', 'tt_number', 'client_id', 'service_id', 'requisition_id', 'category_id',
        'priority_id', 'region_id', 'zone_id', 'woreda_id', 'assigned_to_user_id',
        'current_approver_user_id', 'status', 'document_review_status', 'needs_reverification',
        'building', 'location', 'description', 'assigned_at', 'escalated_at',
        'completed_at', 'rejected_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'document_review_status' => DocumentReviewStatus::class,
            'needs_reverification' => 'boolean',
            'assigned_at' => 'datetime',
            'escalated_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(Requisition::class); }
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
