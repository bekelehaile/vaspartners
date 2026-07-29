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

    protected ?string $description = 'Operational queue — click a card to open the list';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $base = $this->scopedTickets();

        $open = (clone $base)->where('status', TicketStatus::Open)->count();
        $unassigned = (clone $base)
            ->where('status', TicketStatus::Open)
            ->whereNull('assigned_to_user_id')
            ->count();
        $inProgress = (clone $base)->where('status', TicketStatus::InProgress)->count();
        $escalated = (clone $base)
            ->whereNotNull('escalated_at')
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
            ->count();
        $rejected = (clone $base)->where('status', TicketStatus::Rejected)->count();

        $myApproval = Ticket::query()
            ->where('current_approver_user_id', auth()->id())
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]);
        $this->applyDashboardServiceFilter($myApproval);
        $this->applyDashboardDateFilter($myApproval, 'created_at');
        $myApprovalCount = $myApproval->count();

        $completedQuery = TicketResource::getEloquentQuery()
            ->where('status', TicketStatus::Completed);
        $this->applyDashboardServiceFilter($completedQuery);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardDateFilter($completedQuery, 'completed_at');
            $completedLabel = 'Completed in range';
            $completedDescription = 'Approved in selected dates';
        } else {
            $completedQuery->whereDate('completed_at', today());
            $completedLabel = 'Completed today';
            $completedDescription = 'Approved today';
        }
        $completedCount = $completedQuery->count();

        $inProgressDescription = $escalated > 0
            ? $escalated.' escalated'
            : 'Being handled';

        return [
            Stat::make('Unassigned', $unassigned)
                ->description('Open — needs an owner')
                ->descriptionIcon(Heroicon::OutlinedInbox)
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=unassigned'),
            Stat::make('Pending', $open)
                ->description('Open queue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($open > 0 ? 'warning' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=open'),
            Stat::make('In progress', $inProgress)
                ->description($inProgressDescription)
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color($escalated > 0 ? 'danger' : 'info')
                ->url(TicketResource::getUrl('index').'?tab=in_progress'),
            Stat::make('Rejected', $rejected)
                ->description('Sent back to partner')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($rejected > 0 ? 'danger' : 'gray')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make('My approvals', $myApprovalCount)
                ->description('Waiting on you')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color($myApprovalCount > 0 ? 'primary' : 'gray')
                ->url(\App\Filament\Pages\MyTickets::getUrl().'?tab=approval'),
            Stat::make($completedLabel, $completedCount)
                ->description($completedDescription)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(TicketResource::getUrl('index').'?tab=completed'),
        ];
    }

    protected function scopedTickets(): Builder
    {
        return $this->applyDashboardTicketFilters(TicketResource::getEloquentQuery());
    }
}
