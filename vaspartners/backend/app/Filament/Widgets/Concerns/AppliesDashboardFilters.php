<?php

namespace App\Filament\Widgets\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait AppliesDashboardFilters
{
    use InteractsWithPageFilters;

    /** @return array{start_date?: string|null, end_date?: string|null, service_id?: int|string|null} */
    protected function dashboardFilters(): array
    {
        return $this->pageFilters ?? [];
    }

    protected function applyDashboardDateFilter(Builder $query, string $column = 'created_at'): Builder
    {
        $filters = $this->dashboardFilters();

        if (filled($filters['start_date'] ?? null)) {
            $query->whereDate($column, '>=', Carbon::parse($filters['start_date'])->toDateString());
        }

        if (filled($filters['end_date'] ?? null)) {
            $query->whereDate($column, '<=', Carbon::parse($filters['end_date'])->toDateString());
        }

        return $query;
    }

    protected function applyDashboardServiceFilter(Builder $query, string $column = 'service_id'): Builder
    {
        $serviceId = $this->dashboardFilters()['service_id'] ?? null;

        if (filled($serviceId)) {
            $query->where($column, (int) $serviceId);
        }

        return $query;
    }

    protected function applyDashboardTicketFilters(Builder $query, string $dateColumn = 'created_at'): Builder
    {
        $this->applyDashboardDateFilter($query, $dateColumn);
        $this->applyDashboardServiceFilter($query);

        return $query;
    }

    protected function hasCustomDateRange(): bool
    {
        $filters = $this->dashboardFilters();

        return filled($filters['start_date'] ?? null) || filled($filters['end_date'] ?? null);
    }
}
