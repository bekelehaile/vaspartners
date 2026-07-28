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
    ];

    protected function casts(): array
    {
        return [
            'status' => RevenueImportRowStatus::class,
            'amount' => 'decimal:4',
            'raw' => 'array',
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
}
