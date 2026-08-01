<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /**
     * Cached tab badge counts for this request (one aggregate query + one approval count).
     *
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

    public function getTabs(): array
    {
        $counts = fn (): array => $this->tabCounts();
        $userId = auth()->id();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $counts()['all']),
            'unassigned' => Tab::make('Unassigned')
                ->badge(fn (): int => $counts()['unassigned'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', TicketStatus::Open)
                    ->whereNull('assigned_to_user_id')),
            'open' => Tab::make('Pending')
                ->badge(fn (): int => $counts()['open'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Open)),
            'in_progress' => Tab::make('In progress')
                ->badge(fn (): int => $counts()['in_progress'])
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::InProgress)),
            'backlog' => Tab::make('Backlog')
                ->badge(fn (): int => $counts()['backlog'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                    ->whereNotNull('assigned_to_user_id')),
            'rejected' => Tab::make('Rejected')
                ->badge(fn (): int => $counts()['rejected'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Rejected)),
            'approval' => Tab::make('My approval')
                ->badge(fn (): int => $counts()['approval'])
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('current_approver_user_id', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])),
            'completed' => Tab::make('Completed')
                ->badge(fn (): int => $counts()['completed'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Completed)),
            'closed' => Tab::make('Closed')
                ->badge(fn (): int => $counts()['closed'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Closed)),
        ];
    }

    /**
     * @return array{
     *   all: int,
     *   unassigned: int,
     *   open: int,
     *   in_progress: int,
     *   backlog: int,
     *   rejected: int,
     *   approval: int,
     *   completed: int,
     *   closed: int
     * }
     */
    protected function tabCounts(): array
    {
        if ($this->tabCounts !== null) {
            return $this->tabCounts;
        }

        $open = TicketStatus::Open->value;
        $inProgress = TicketStatus::InProgress->value;
        $rejected = TicketStatus::Rejected->value;
        $completed = TicketStatus::Completed->value;
        $closed = TicketStatus::Closed->value;

        $row = TicketResource::getEloquentQuery()
            ->toBase()
            ->selectRaw(
                'count(*)::int as c_all,
                count(*) filter (where status = ? and assigned_to_user_id is null)::int as c_unassigned,
                count(*) filter (where status = ?)::int as c_open,
                count(*) filter (where status = ?)::int as c_in_progress,
                count(*) filter (where status in (?, ?) and assigned_to_user_id is not null)::int as c_backlog,
                count(*) filter (where status = ?)::int as c_rejected,
                count(*) filter (where status = ?)::int as c_completed,
                count(*) filter (where status = ?)::int as c_closed',
                [$open, $open, $inProgress, $open, $inProgress, $rejected, $completed, $closed],
            )
            ->first();

        $approval = Ticket::query()
            ->where('current_approver_user_id', auth()->id())
            ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
            ->count();

        return $this->tabCounts = [
            'all' => (int) ($row->c_all ?? 0),
            'unassigned' => (int) ($row->c_unassigned ?? 0),
            'open' => (int) ($row->c_open ?? 0),
            'in_progress' => (int) ($row->c_in_progress ?? 0),
            'backlog' => (int) ($row->c_backlog ?? 0),
            'rejected' => (int) ($row->c_rejected ?? 0),
            'approval' => $approval,
            'completed' => (int) ($row->c_completed ?? 0),
            'closed' => (int) ($row->c_closed ?? 0),
        ];
    }
}
