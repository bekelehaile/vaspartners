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
        'sheet_name',
        'service_family',
        'row_number',
        'service_id',
        'partner_name',
        'service_type',
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
            'service_family' => \App\Enums\RevenueServiceFamily::class,
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
}
