<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServiceProvisioningMedia extends Model
{
    protected $table = 'service_provisioning_media';

    protected $fillable = [
        'service_provisioning_step_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by_type',
        'uploaded_by_id',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(ServiceProvisioningStep::class, 'service_provisioning_step_id');
    }

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
