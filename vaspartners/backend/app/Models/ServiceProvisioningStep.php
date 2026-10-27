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
        return intdiv($this->hoursInStep(), 24);
    }

    public function hoursInStep(): int
    {
        $start = $this->created_at;
        if (! $start) {
            return 0;
        }

        $end = $this->completed_at ?? Carbon::now();

        return max(0, (int) $start->diffInHours($end));
    }

    /**
     * Compact OLA elapsed label, e.g. "5h", "2d", "2d 5h".
     */
    public function elapsedOlaLabel(): string
    {
        return static::formatOlaElapsed($this->hoursInStep());
    }

    public static function formatOlaElapsed(int $hours): string
    {
        $hours = max(0, $hours);
        $days = intdiv($hours, 24);
        $remainder = $hours % 24;

        if ($days > 0 && $remainder > 0) {
            return $days.'d '.$remainder.'h';
        }

        if ($days > 0) {
            return $days.'d';
        }

        return $remainder.'h';
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
