<?php

namespace App\Filament\Widgets;

use App\Services\AccountManagerPerformanceService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountManagerPerformanceOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Team performance';

    protected ?string $description = 'Account handlers — backlog, throughput, and speed for the selected period';

    protected function getStats(): array
    {
        $filters = $this->pageFilters ?? [];
        $summary = app(AccountManagerPerformanceService::class)->teamSummary([
            'start' => $filters['start_date'] ?? null,
            'end' => $filters['end_date'] ?? null,
            'service_id' => filled($filters['service_id'] ?? null) ? (int) $filters['service_id'] : null,
            'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        ]);

        $cycle = $summary['avg_cycle_hours'];
        $pickup = $summary['avg_pickup_hours'];
        $reject = $summary['rejection_rate'];

        return [
            Stat::make('Active handlers', $summary['handlers'])
                ->description('With assigned requests')
                ->color('primary'),
            Stat::make('Backlog', $summary['backlog'])
                ->description($summary['unassigned_open'].' still unassigned (open)')
                ->color($summary['backlog'] > 0 ? 'warning' : 'success'),
            Stat::make('Completed', $summary['completed'])
                ->description('Finished in period')
                ->color('success'),
            Stat::make('Avg cycle', $cycle !== null ? $cycle.' h' : '—')
                ->description('Assign → complete')
                ->color($cycle !== null && $cycle > 72 ? 'danger' : 'gray'),
            Stat::make('Avg pickup', $pickup !== null ? $pickup.' h' : '—')
                ->description('Create → assign')
                ->color($pickup !== null && $pickup > 24 ? 'warning' : 'gray'),
            Stat::make('Rejection rate', $reject !== null ? $reject.'%' : '—')
                ->description('Of period outcomes')
                ->color($reject !== null && $reject >= 20 ? 'danger' : 'gray'),
        ];
    }
}
