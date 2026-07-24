<?php

namespace App\Models;

use App\Enums\BulkMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkMessage extends Model
{
    use HasUlids;

    protected $table = 'bulk_messages';

    protected $fillable = [
        'public_id',
        'title',
        'message',
        'source_filename',
        'source_path',
        'status',
        'created_by_user_id',
        'total_count',
        'matched_count',
        'sent_count',
        'failed_count',
        'skipped_count',
        'queued_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BulkMessageStatus::class,
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkMessageRecipient::class, 'campaign_id');
    }

    public function refreshCounts(): void
    {
        $this->forceFill([
            'total_count' => $this->recipients()->count(),
            'matched_count' => $this->recipients()->whereNotNull('company_id')->count(),
            'sent_count' => $this->recipients()->where('status', 'sent')->count(),
            'failed_count' => $this->recipients()->where('status', 'failed')->count(),
            'skipped_count' => $this->recipients()->where('status', 'skipped')->count(),
        ])->save();
    }
}
