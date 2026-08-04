<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Services\AccountManagerWorkloadService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountManagerWorkloadOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Live workload';

    protected ?string $description = 'Current ticket status counts for assigned account handlers';

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

        $summary = app(AccountManagerWorkloadService::class)->teamSummary([
            'service_ids' => $serviceIds,
            'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        ]);

        return [
            Stat::make('Handlers', $summary['handlers'])
                ->description('With assigned requests')
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->color('primary'),
            Stat::make(TicketStatus::Open->label(), $summary['open'])
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($summary['open'] > 0 ? TicketStatus::Open->getColor() : 'gray'),
            Stat::make(TicketStatus::InProgress->label(), $summary['in_progress'])
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color($summary['in_progress'] > 0 ? TicketStatus::InProgress->getColor() : 'gray'),
            Stat::make('Holding', $summary['holding'])
                ->description('Pending + in progress')
                ->descriptionIcon(Heroicon::OutlinedInboxStack)
                ->color($summary['holding'] > 0 ? 'warning' : 'success'),
            Stat::make(TicketStatus::Rejected->label(), $summary['rejected'])
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($summary['rejected'] > 0 ? TicketStatus::Rejected->getColor() : 'gray'),
            Stat::make(TicketStatus::Completed->label(), $summary['completed'])
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color($summary['completed'] > 0 ? TicketStatus::Completed->getColor() : 'gray'),
            Stat::make(TicketStatus::Closed->label(), $summary['closed'])
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('gray'),
        ];
    }
}
