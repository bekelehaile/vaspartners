<?php

namespace App\Models;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class ServiceProvisioningStep extends Model
{
    protected $fillable = [
        'service_provisioning_id',
        'step_key',
        'label',
        'seq',
        'parallel_group',
        'from_department',
        'to_department',
        'status',
        'payload',
        'remark',
        'created_by_type',
        'created_by_id',
        'completed_by_type',
        'completed_by_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProvisioningStepStatus::class,
            'from_department' => ProvisioningDepartment::class,
            'to_department' => ProvisioningDepartment::class,
            'payload' => 'array',
            'seq' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function provisioning(): BelongsTo
    {
        return $this->belongsTo(ServiceProvisioning::class, 'service_provisioning_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ServiceProvisioningMedia::class);
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function completedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function daysInStep(): int
    {
        $start = $this->created_at;
        if (! $start) {
            return 0;
        }

        $end = $this->completed_at ?? Carbon::now();

        return max(0, (int) $start->diffInDays($end));
    }

    public function whereLabel(): string
    {
        $from = $this->from_department instanceof ProvisioningDepartment
            ? $this->from_department->label()
            : (string) $this->from_department;
        $to = $this->to_department instanceof ProvisioningDepartment
            ? $this->to_department->label()
            : (string) $this->to_department;

        return $from.' → '.$to;
    }
}
