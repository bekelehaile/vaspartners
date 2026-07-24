<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAssignment extends Model
{
    protected $fillable = [
        'ticket_id', 'assigned_by_user_id', 'assigned_to_user_id', 'priority_id', 'note',
    ];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by_user_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
}
