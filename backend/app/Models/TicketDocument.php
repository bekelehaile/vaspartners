<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id', 'document_type_id', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'verification_status', 'remark', 'uploaded_by_client_id',
    ];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function documentType(): BelongsTo { return $this->belongsTo(DocumentType::class); }
}
