<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use App\Services\AccountManagerPerformanceService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountManagerPerformanceOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Team performance';

    protected ?string $description = 'Per-handler Completed / Closed are in the leaderboard below — click a card to open the matching list';

    protected function getStats(): array
    {
        $filters = $this->pageFilters ?? [];
        $serviceIds = collect(\Illuminate\Support\Arr::wrap($filters['service_ids'] ?? []))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $summary = app(AccountManagerPerformanceService::class)->teamSummary([
            'start' => $filters['start_date'] ?? null,
            'end' => $filters['end_date'] ?? null,
            'service_ids' => $serviceIds,
            'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        ]);

        $cycle = $summary['avg_cycle_hours'];
        $pickup = $summary['avg_pickup_hours'];
        $reject = $summary['rejection_rate'];
        $handlerId = filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null;

        return [
            Stat::make('Active handlers', $summary['handlers'])
                ->description('With assigned requests')
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->color('primary')
                ->url(UserResource::getUrl('index')),
            Stat::make('Backlog', $summary['backlog'])
                ->description($summary['unassigned_open'].' still Pending')
                ->descriptionIcon(Heroicon::OutlinedInboxStack)
                ->color($summary['backlog'] > 0 ? 'warning' : 'success')
                ->url($this->ticketsUrl('in_progress', $handlerId, $serviceIds)),
            Stat::make('Completed', $summary['completed'])
                ->description('Completed in period')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url($this->ticketsUrl('completed', $handlerId, $serviceIds)),
            Stat::make('Closed', $summary['closed'] ?? 0)
                ->description('Closed in period')
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url($this->ticketsUrl('closed', $handlerId, $serviceIds)),
            Stat::make('Rejected', $summary['rejected'] ?? 0)
                ->description('Rejected in period')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color(($summary['rejected'] ?? 0) > 0 ? 'danger' : 'gray')
                ->url($this->ticketsUrl('rejected', $handlerId, $serviceIds)),
            Stat::make('Avg cycle', $cycle !== null ? $cycle.' h' : '—')
                ->description('Assign → outcome')
                ->color($cycle !== null && $cycle > 72 ? 'danger' : 'gray'),
            Stat::make('Avg pickup', $pickup !== null ? $pickup.' h' : '—')
                ->description('Create → assign')
                ->color($pickup !== null && $pickup > 24 ? 'warning' : 'gray'),
            Stat::make('Rejection rate', $reject !== null ? $reject.'%' : '—')
                ->description('Of closed + rejected in period')
                ->color($reject !== null && $reject >= 20 ? 'danger' : 'gray')
                ->url($this->ticketsUrl('rejected', $handlerId, $serviceIds)),
        ];
    }

    /**
     * @param  list<int>  $serviceIds
     */
    protected function ticketsUrl(string $tab, ?int $handlerId = null, array $serviceIds = []): string
    {
        $url = TicketResource::getUrl('index').'?tab='.urlencode($tab);
        $tableFilters = [];

        if ($handlerId) {
            $tableFilters['assigned_to_user_id'] = ['value' => $handlerId];
        }

        if ($serviceIds !== []) {
            $tableFilters['service_id'] = ['values' => array_map('strval', $serviceIds)];
        }

        if ($tableFilters !== []) {
            $url .= '&'.http_build_query(['tableFilters' => $tableFilters]);
        }

        return $url;
    }
}
