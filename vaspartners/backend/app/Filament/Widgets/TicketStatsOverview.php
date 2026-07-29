<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\Ticket;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class TicketStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Service requests';

    protected ?string $description = 'Each card uses its status event time: Pending→opened_at · In progress→in_progress_at · Completed/Closed/Rejected→*_at — click to open';

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
            // Escalation age in the selected window uses escalated_at.
            $this->applyDashboardDateFilter($escalatedQuery, 'escalated_at');
        }
        $escalated = $escalatedQuery->count();

        $myApproval = Ticket::query()
            ->where('current_approver_user_id', auth()->id())
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]);
        $this->applyDashboardLiveTicketFilters($myApproval);
        $myApprovalCount = $myApproval->count();

        [$completedCount, $completedLabel, $completedDescription] = $this->outcomeStat(
            TicketStatus::Completed,
            'completed_at',
            'Completed today',
            'Completed in range',
            'completed_at today',
            'completed_at in range',
        );

        [$closedCount, $closedLabel, $closedDescription] = $this->outcomeStat(
            TicketStatus::Closed,
            'closed_at',
            'Closed today',
            'Closed in range',
            'closed_at today',
            'closed_at in range',
        );

        [$rejectedCount, $rejectedLabel, $rejectedDescription] = $this->outcomeStat(
            TicketStatus::Rejected,
            'rejected_at',
            'Rejected today',
            'Rejected in range',
            'rejected_at today',
            'rejected_at in range',
        );

        $dated = $this->hasCustomDateRange();
        $pendingDescription = $dated ? 'opened_at in range' : 'Open queue (opened_at)';
        $unassignedDescription = $dated ? 'Open · opened_at in range' : 'Open — needs an owner';
        $inProgressDescription = $escalated > 0
            ? $escalated.' escalated'
            : ($dated ? 'in_progress_at in range' : 'Being handled');

        return [
            Stat::make('Unassigned', $unassigned)
                ->description($unassignedDescription)
                ->descriptionIcon(Heroicon::OutlinedInbox)
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=unassigned'),
            Stat::make('Pending', $open)
                ->description($pendingDescription)
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($open > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=open'),
            Stat::make('In progress', $inProgress)
                ->description($inProgressDescription)
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color($escalated > 0 ? 'danger' : 'info')
                ->url(TicketResource::getUrl('index').'?tab=in_progress'),
            Stat::make('My approvals', $myApprovalCount)
                ->description('Waiting on you (live)')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color($myApprovalCount > 0 ? 'primary' : 'gray')
                ->url(\App\Filament\Pages\MyTickets::getUrl().'?tab=approval'),
            Stat::make($rejectedLabel, $rejectedCount)
                ->description($rejectedDescription)
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($rejectedCount > 0 ? 'danger' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make($completedLabel, $completedCount)
                ->description($completedDescription)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(TicketResource::getUrl('index').'?tab=completed'),
            Stat::make($closedLabel, $closedCount)
                ->description($closedDescription)
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(TicketResource::getUrl('index').'?tab=closed'),
        ];
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    protected function outcomeStat(
        TicketStatus $status,
        string $column,
        string $todayLabel,
        string $rangeLabel,
        string $todayDescription,
        string $rangeDescription,
    ): array {
        $query = TicketResource::getEloquentQuery()->where('status', $status);
        $this->applyDashboardServiceFilter($query);
        $this->applyDashboardEventDateFilter($query, $column, defaultToToday: true);

        $inRange = $this->hasCustomDateRange();

        return [
            $query->count(),
            $inRange ? $rangeLabel : $todayLabel,
            $inRange ? $rangeDescription : $todayDescription,
        ];
    }
}
