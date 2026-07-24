<?php

namespace App\Models;

use App\Enums\BulkMessageRecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkMessageRecipient extends Model
{
    protected $table = 'bulk_message_recipients';

    protected $fillable = [
        'campaign_id',
        'company_id',
        'phone_raw',
        'phone_normalized',
        'company_name',
        'company_tin',
        'row_number',
        'status',
        'error',
        'attempts',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BulkMessageRecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function bulkMessage(): BelongsTo
    {
        return $this->belongsTo(BulkMessage::class, 'campaign_id');
    }

    /** @deprecated use bulkMessage() */
    public function campaign(): BelongsTo
    {
        return $this->bulkMessage();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
