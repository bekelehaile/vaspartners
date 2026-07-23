<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketApprovalStep extends Model
{
    protected $fillable = [
        'ticket_id', 'approver_user_id', 'action', 'document_review_snapshot',
        'is_final', 'escalated_to_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'is_final' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_user_id'); }
}
