<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningWorkflow;
use App\Support\TimestampPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ServiceProvisioning extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'subscription_id',
        'service_id',
        'company_id',
        'workflow_key',
        'status',
        'current_step_key',
        'started_at',
        'completed_at',
        'created_by_type',
        'created_by_id',
        'shared_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProvisioningStatus::class,
            'workflow_key' => ProvisioningWorkflow::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            // JSON column — encrypt sensitive credential keys in application code, not the whole blob.
            'shared_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->public_id)) {
                $prefix = (string) config('provisioning_workflows.public_id_prefix', 'PRV-');
                $model->public_id = $prefix.TimestampPublicId::generate(
                    now(),
                    fn (string $id): bool => self::query()->where('public_id', $prefix.$id)->exists(),
                );
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ServiceProvisioningStep::class)->orderBy('seq');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ServiceProvisioningMessage::class)->orderBy('id');
    }

    public function media(): HasManyThrough
    {
        return $this->hasManyThrough(
            ServiceProvisioningMedia::class,
            ServiceProvisioningStep::class,
            'service_provisioning_id',
            'service_provisioning_step_id',
            'id',
            'id',
        )->latest('service_provisioning_media.id');
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function overallElapsedDays(): int
    {
        $start = $this->started_at ?? $this->created_at;
        if (! $start) {
            return 0;
        }

        $end = $this->completed_at ?? Carbon::now();

        return max(0, (int) $start->diffInDays($end));
    }

    public function currentStep(): ?ServiceProvisioningStep
    {
        if (! $this->current_step_key) {
            return null;
        }

        return $this->steps->firstWhere('step_key', $this->current_step_key)
            ?? $this->steps()->where('step_key', $this->current_step_key)->first();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
