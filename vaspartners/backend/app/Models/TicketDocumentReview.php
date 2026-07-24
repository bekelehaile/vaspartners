<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDocumentReview extends Model
{
    protected $fillable = ['ticket_id', 'reviewed_by_user_id', 'result', 'note'];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
}
