<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceProvisioningMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_provisioning_id',
        'author_type',
        'author_id',
        'body',
        'is_public',
        'attachment_disk',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'attachment_size_bytes' => 'integer',
        ];
    }

    public function provisioning(): BelongsTo
    {
        return $this->belongsTo(ServiceProvisioning::class, 'service_provisioning_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }
}
