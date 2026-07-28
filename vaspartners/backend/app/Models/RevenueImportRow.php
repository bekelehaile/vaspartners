<?php

namespace App\Models;

use App\Enums\RevenueImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueImportRow extends Model
{
    protected $fillable = [
        'revenue_import_id',
        'revenue_partner_id',
        'vas_service_id',
        'row_number',
        'service_id',
        'partner_name',
        'short_code',
        'amount',
        'amount_raw',
        'status',
        'error',
        'raw',
        'bulk_message_id',
        'bulk_message_recipient_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RevenueImportRowStatus::class,
            'amount' => 'decimal:4',
            'raw' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(RevenueImport::class, 'revenue_import_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(RevenuePartner::class, 'revenue_partner_id');
    }

    public function vasService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'vas_service_id');
    }

    public function bulkMessage(): BelongsTo
    {
        return $this->belongsTo(BulkMessage::class, 'bulk_message_id');
    }

    public function smsRecipient(): BelongsTo
    {
        return $this->belongsTo(BulkMessageRecipient::class, 'bulk_message_recipient_id');
    }

    public function wasSent(): bool
    {
        return $this->status === RevenueImportRowStatus::Sent
            || filled($this->sent_at)
            || filled($this->bulk_message_id);
    }
}
