<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class TicketStatsOverview extends StatsOverviewWidget
{
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
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
            ->count();
        $completedToday = (clone $base)
            ->where('status', TicketStatus::Completed)
            ->whereDate('completed_at', today())
            ->count();

        return [
            Stat::make('Open', $open)
                ->description($unassigned.' unassigned')
                ->descriptionIcon(Heroicon::OutlinedInbox)
                ->color('warning')
                ->url(TicketResource::getUrl('index').'?tab=unassigned'),
            Stat::make('In progress', $inProgress)
                ->description('Being handled')
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->url(TicketResource::getUrl('index').'?tab=in_progress'),
            Stat::make('Needs update', $rejected)
                ->description('Sent back to partner')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(TicketResource::getUrl('index').'?tab=rejected'),
            Stat::make('My approvals', $myApproval)
                ->description('Waiting on you')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color('primary')
                ->url(TicketResource::getUrl('index').'?tab=approval'),
            Stat::make('Completed today', $completedToday)
                ->description('Approved today')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(TicketResource::getUrl('index').'?tab=completed'),
        ];
    }

    protected function scopedTickets(): Builder
    {
        return TicketResource::getEloquentQuery();
    }
}
