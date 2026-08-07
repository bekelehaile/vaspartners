<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\Ticket;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TicketStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Service requests';

    protected ?string $description = 'Queue is live. Rejected uses the selected period (default last month). Click a card to open the list.';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        // Live operational queue (not narrowed by date — otherwise empty periods look “broken”).
        $live = $this->applyDashboardLiveTicketFilters(TicketResource::getEloquentQuery());

        $open = (clone $live)->where('status', TicketStatus::Open)->count();
        $inProgress = (clone $live)->where('status', TicketStatus::InProgress)->count();
        $escalated = (clone $live)
            ->whereNotNull('escalated_at')
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
            ->count();
        // Live totals — Completed is rarely a lasting status (tickets move to Closed).
        $completed = (clone $live)->where('status', TicketStatus::Completed)->count();
        $closed = (clone $live)->where('status', TicketStatus::Closed)->count();

        $myApproval = Ticket::query()
            ->where('current_approver_user_id', auth()->id())
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]);
        $this->applyDashboardLiveTicketFilters($myApproval);
        $myApprovalCount = $myApproval->count();

        // Rejected in selected period (defaults to last month on the dashboard).
        $rejectedQuery = TicketResource::getEloquentQuery()->where('status', TicketStatus::Rejected);
        $this->applyDashboardServiceFilter($rejectedQuery);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardEventDateFilter($rejectedQuery, 'rejected_at', defaultToToday: false);
        }
        $rejectedCount = $rejectedQuery->count();

        return [
            Stat::make('Pending', $open)
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($open > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=open'),
            Stat::make('In progress', $inProgress)
                ->description($escalated > 0 ? $escalated.' escalated' : null)
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color($escalated > 0 ? 'danger' : 'info')
                ->url(TicketResource::getUrl('index').'?tab=in_progress'),
            Stat::make('My approvals', $myApprovalCount)
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color($myApprovalCount > 0 ? 'primary' : 'gray')
                ->url(\App\Filament\Pages\MyTickets::getUrl().'?tab=in_progress'),
            Stat::make('Rejected', $rejectedCount)
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($rejectedCount > 0 ? 'danger' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make('Completed', $completed)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(TicketResource::getUrl('index').'?tab=completed'),
            Stat::make('Closed', $closed)
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(TicketResource::getUrl('index').'?tab=closed'),
        ];
    }
}
