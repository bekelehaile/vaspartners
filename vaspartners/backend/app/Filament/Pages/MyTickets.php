<?php

namespace App\Filament\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class MyTickets extends Page implements HasActions, HasSchemas, HasTable
{
    use HasPageShield;
    use HasTabs;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'My Tickets';

    protected static ?string $title = 'My Tickets';

    protected static string|\UnitEnum|null $navigationGroup = 'Tickets';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'my-tickets';

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function getSubheading(): ?string
    {
        return 'Tickets assigned to you, and requests waiting for your approval.';
    }

    public static function getNavigationBadge(): ?string
    {
        $userId = auth()->id();
        if (! $userId) {
            return null;
        }

        $count = Ticket::query()
            ->where(function (Builder $q) use ($userId) {
                $q->where('assigned_to_user_id', $userId)
                    ->orWhere('current_approver_user_id', $userId);
            })
            ->where('status', TicketStatus::Open)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending tickets assigned to you or waiting for your approval';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    /**
     * Tickets the current user owns as AM, or that are waiting on them to approve.
     */
    protected function baseQuery(): Builder
    {
        $userId = auth()->id();

        return Ticket::query()->where(function (Builder $q) use ($userId) {
            $q->where('assigned_to_user_id', $userId)
                ->orWhere('current_approver_user_id', $userId);
        });
    }

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
     * Cached tab badge counts for this request (one aggregate query).
     *
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

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

        $row = $this->baseQuery()
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

    public function table(Table $table): Table
    {
        return TicketResource::table($table)
            ->query(function (): Builder {
                $query = $this->baseQuery();

                return $this->modifyQueryWithActiveTab($query);
            })
            ->recordUrl(null);
    }
}
