<?php

namespace App\Models;

use App\Enums\RevenueImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueImport extends Model
{
    use HasUlids;

    protected $fillable = [
        'public_id',
        'title',
        'period',
        'vas_service_id',
        'source_filename',
        'filament_import_id',
        'status',
        'message_template',
        'created_by_user_id',
        'sent_by_user_id',
        'bulk_message_id',
        'total_count',
        'valid_count',
        'matched_count',
        'sent_count',
        'missing_partner_count',
        'missing_phone_count',
        'invalid_count',
        'imported_at',
        'sent_at',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): bool {
            return false;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => RevenueImportStatus::class,
            'imported_at' => 'datetime',
            'sent_at' => 'datetime',
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

    public function vasService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'vas_service_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function bulkMessage(): BelongsTo
    {
        return $this->belongsTo(BulkMessage::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RevenueImportRow::class);
    }

    /** Import matching / amount rows that have not been queued for SMS yet. */
    public function payloadRows(): HasMany
    {
        return $this->hasMany(RevenueImportRow::class)
            ->where('status', '!=', 'sent');
    }

    /** Rows already queued/sent for SMS (retry / delivery tracking). */
    public function sentRows(): HasMany
    {
        return $this->hasMany(RevenueImportRow::class)
            ->where('status', 'sent');
    }

    public function refreshCounts(): void
    {
        $this->forceFill([
            'total_count' => $this->rows()->count(),
            'valid_count' => $this->rows()->whereIn('status', [
                'matched',
                'sent',
                'missing_partner',
                'missing_phone',
            ])->count(),
            'matched_count' => $this->rows()->where('status', 'matched')->count(),
            'sent_count' => $this->rows()->where('status', 'sent')->count(),
            'missing_partner_count' => $this->rows()->where('status', 'missing_partner')->count(),
            'missing_phone_count' => $this->rows()->where('status', 'missing_phone')->count(),
            'invalid_count' => $this->rows()->whereIn('status', ['invalid', 'duplicate'])->count(),
        ])->save();
    }

    public function resolveStatusFromRows(): void
    {
        $this->refreshCounts();

        if ($this->matched_count > 0
            && $this->missing_partner_count === 0
            && $this->missing_phone_count === 0) {
            $status = RevenueImportStatus::Ready;
        } elseif ($this->total_count === 0
            || ($this->matched_count === 0
                && $this->sent_count === 0
                && $this->missing_partner_count === 0
                && $this->missing_phone_count === 0)) {
            $status = RevenueImportStatus::Failed;
        } elseif ($this->matched_count === 0
            && $this->sent_count > 0
            && $this->missing_partner_count === 0
            && $this->missing_phone_count === 0) {
            // All Ready rows were sent; nothing left unresolved.
            $status = RevenueImportStatus::Completed;
        } else {
            $status = RevenueImportStatus::Reviewing;
        }

        if (! in_array($this->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            $this->forceFill(['status' => $status])->save();
        }
    }
}
