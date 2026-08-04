<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /**
     * Cached tab badge counts for this request (one aggregate query).
     *
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

    public function getTabs(): array
    {
        $counts = fn (): array => $this->tabCounts();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $counts()['all']),
            'open' => Tab::make(TicketStatus::Open->label())
                ->badge(fn (): int => $counts()['open'])
                ->badgeColor(TicketStatus::Open->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Open)),
            'in_progress' => Tab::make(TicketStatus::InProgress->label())
                ->badge(fn (): int => $counts()['in_progress'])
                ->badgeColor(TicketStatus::InProgress->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::InProgress)),
            'rejected' => Tab::make(TicketStatus::Rejected->label())
                ->badge(fn (): int => $counts()['rejected'])
                ->badgeColor(TicketStatus::Rejected->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Rejected)),
            'completed' => Tab::make(TicketStatus::Completed->label())
                ->badge(fn (): int => $counts()['completed'])
                ->badgeColor(TicketStatus::Completed->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Completed)),
            'closed' => Tab::make(TicketStatus::Closed->label())
                ->badge(fn (): int => $counts()['closed'])
                ->badgeColor(TicketStatus::Closed->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Closed)),
        ];
    }

    /**
     * @return array{
     *   all: int,
     *   open: int,
     *   in_progress: int,
     *   rejected: int,
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
                count(*) filter (where status = ?)::int as c_open,
                count(*) filter (where status = ?)::int as c_in_progress,
                count(*) filter (where status = ?)::int as c_rejected,
                count(*) filter (where status = ?)::int as c_completed,
                count(*) filter (where status = ?)::int as c_closed',
                [$open, $inProgress, $rejected, $completed, $closed],
            )
            ->first();

        return $this->tabCounts = [
            'all' => (int) ($row->c_all ?? 0),
            'open' => (int) ($row->c_open ?? 0),
            'in_progress' => (int) ($row->c_in_progress ?? 0),
            'rejected' => (int) ($row->c_rejected ?? 0),
            'completed' => (int) ($row->c_completed ?? 0),
            'closed' => (int) ($row->c_closed ?? 0),
        ];
    }
}
