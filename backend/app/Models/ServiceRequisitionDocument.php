<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequisitionDocument extends Model
{
    protected $fillable = [
        'service_id', 'requisition_id', 'document_type_id', 'is_required', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(Requisition::class); }
    public function documentType(): BelongsTo { return $this->belongsTo(DocumentType::class); }
}
