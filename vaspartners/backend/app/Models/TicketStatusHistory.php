<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TicketStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function actor(): MorphTo { return $this->morphTo(); }
}
