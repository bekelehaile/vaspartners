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

    protected static ?int $sort = 2;

    protected ?string $heading = 'Service requests';

    protected ?string $description = 'Operational queue — click a card to open the list';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $pendingQuery = TicketResource::getEloquentQuery();
        $this->applyDashboardTicketStatusFilters($pendingQuery, TicketStatus::Open);
        $open = $pendingQuery->count();

        $unassignedQuery = TicketResource::getEloquentQuery()
            ->whereNull('assigned_to_user_id');
        $this->applyDashboardTicketStatusFilters($unassignedQuery, TicketStatus::Open);
        $unassigned = $unassignedQuery->count();

        $inProgressQuery = TicketResource::getEloquentQuery();
        $this->applyDashboardTicketStatusFilters($inProgressQuery, TicketStatus::InProgress);
        $inProgress = $inProgressQuery->count();

        $escalatedQuery = TicketResource::getEloquentQuery()
            ->whereNotNull('escalated_at')
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]);
        $this->applyDashboardServiceFilter($escalatedQuery);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardDateFilter($escalatedQuery, 'escalated_at');
        }
        $escalated = $escalatedQuery->count();

        $myApproval = Ticket::query()
            ->where('current_approver_user_id', auth()->id())
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]);
        $this->applyDashboardLiveTicketFilters($myApproval);
        $myApprovalCount = $myApproval->count();

        $completedCount = $this->outcomeCount(TicketStatus::Completed, 'completed_at');
        $closedCount = $this->outcomeCount(TicketStatus::Closed, 'closed_at');
        $rejectedCount = $this->outcomeCount(TicketStatus::Rejected, 'rejected_at');

        return [
            Stat::make('Unassigned', $unassigned)
                ->descriptionIcon(Heroicon::OutlinedInbox)
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=unassigned'),
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
                ->url(\App\Filament\Pages\MyTickets::getUrl().'?tab=approval'),
            Stat::make('Rejected', $rejectedCount)
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($rejectedCount > 0 ? 'danger' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make('Completed', $completedCount)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(TicketResource::getUrl('index').'?tab=completed'),
            Stat::make('Closed', $closedCount)
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(TicketResource::getUrl('index').'?tab=closed'),
        ];
    }

    protected function outcomeCount(TicketStatus $status, string $column): int
    {
        $query = TicketResource::getEloquentQuery()->where('status', $status);
        $this->applyDashboardServiceFilter($query);
        $this->applyDashboardEventDateFilter($query, $column, defaultToToday: true);

        return $query->count();
    }
}
