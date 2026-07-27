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

    protected static ?int $sort = 1;

    protected ?string $heading = 'Service requests';

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

        return [
            Stat::make('Pending', $open)
                ->description($unassigned.' unassigned')
                ->descriptionIcon(Heroicon::OutlinedInbox)
                ->color('warning')
                ->url(TicketResource::getUrl('index').'?tab=unassigned'),
            Stat::make('In progress', $inProgress)
                ->description('Being handled')
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->url(TicketResource::getUrl('index').'?tab=in_progress'),
            Stat::make('Rejected', $rejected)
                ->description('Sent back to partner')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make('My approvals', $myApprovalCount)
                ->description('Waiting on you')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color('primary')
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
