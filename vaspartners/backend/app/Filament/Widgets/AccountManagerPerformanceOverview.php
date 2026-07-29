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

    protected ?string $description = 'Account handlers — click a card to open the matching list';

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
        $handlerId = filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null;

        return [
            Stat::make('Active handlers', $summary['handlers'])
                ->description('With assigned requests')
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->color('primary')
                ->url(UserResource::getUrl('index')),
            Stat::make('Backlog', $summary['backlog'])
                ->description($summary['unassigned_open'].' still unassigned (open)')
                ->descriptionIcon(Heroicon::OutlinedInboxStack)
                ->color($summary['backlog'] > 0 ? 'warning' : 'success')
                ->url($this->ticketsUrl('backlog', $handlerId)),
            Stat::make('Completed', $summary['completed'])
                ->description('Finished in period')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url($this->ticketsUrl('completed', $handlerId)),
            Stat::make('Avg cycle', $cycle !== null ? $cycle.' h' : '—')
                ->description('Assign → complete')
                ->color($cycle !== null && $cycle > 72 ? 'danger' : 'gray'),
            Stat::make('Avg pickup', $pickup !== null ? $pickup.' h' : '—')
                ->description('Create → assign')
                ->color($pickup !== null && $pickup > 24 ? 'warning' : 'gray'),
            Stat::make('Rejection rate', $reject !== null ? $reject.'%' : '—')
                ->description('Of period outcomes')
                ->color($reject !== null && $reject >= 20 ? 'danger' : 'gray')
                ->url($this->ticketsUrl('rejected', $handlerId)),
        ];
    }

    protected function ticketsUrl(string $tab, ?int $handlerId = null): string
    {
        $url = TicketResource::getUrl('index').'?tab='.urlencode($tab);

        if ($handlerId) {
            // Filament table filter query string for assignee (added below on TicketResource).
            $url .= '&'.http_build_query([
                'tableFilters' => [
                    'assigned_to_user_id' => ['value' => $handlerId],
                ],
            ]);
        }

        return $url;
    }
}
