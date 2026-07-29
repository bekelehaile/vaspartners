<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\TicketStatus;
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

    /**
     * Event timestamp that defines each ticket status for period KPIs.
     */
    protected function ticketEventColumnForStatus(TicketStatus $status): string
    {
        return match ($status) {
            TicketStatus::Open => 'opened_at',
            TicketStatus::InProgress => 'in_progress_at',
            TicketStatus::Completed => 'completed_at',
            TicketStatus::Closed => 'closed_at',
            TicketStatus::Rejected => 'rejected_at',
        };
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

    /**
     * Service filter only — for live operational queues (open / in progress / awaiting approval).
     */
    protected function applyDashboardLiveTicketFilters(Builder $query): Builder
    {
        return $this->applyDashboardServiceFilter($query);
    }

    /**
     * Period (or today) filter on a specific event timestamp.
     * Requires the column to be set so rows without that event are excluded.
     */
    protected function applyDashboardEventDateFilter(Builder $query, string $column, bool $defaultToToday = true): Builder
    {
        $query->whereNotNull($column);

        if ($this->hasCustomDateRange()) {
            $this->applyDashboardDateFilter($query, $column);
        } elseif ($defaultToToday) {
            $query->whereDate($column, today());
        }

        return $query;
    }

    /**
     * Count tickets in a status using that status's own event timestamp when a date range is set.
     * Without a date range: live current status (no date filter).
     */
    protected function applyDashboardTicketStatusFilters(Builder $query, TicketStatus $status): Builder
    {
        $this->applyDashboardServiceFilter($query);
        $query->where('status', $status);

        if ($this->hasCustomDateRange()) {
            $column = $this->ticketEventColumnForStatus($status);
            $query->whereNotNull($column);
            $this->applyDashboardDateFilter($query, $column);
        }

        return $query;
    }

    /**
     * @deprecated Prefer applyDashboardLiveTicketFilters / applyDashboardTicketStatusFilters /
     *             applyDashboardEventDateFilter so each KPI uses the correct timestamp.
     */
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
