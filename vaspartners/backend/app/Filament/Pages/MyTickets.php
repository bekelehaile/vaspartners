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
            ->whereNotIn('status', [
                TicketStatus::Closed->value,
                TicketStatus::Completed->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
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
        $base = fn (): Builder => $this->baseQuery();
        $userId = auth()->id();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'approval' => Tab::make('My approval')
                ->badge(fn (): int => Ticket::query()
                    ->where('current_approver_user_id', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
                    ->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('current_approver_user_id', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])),
            'in_progress' => Tab::make('In progress')
                ->badge(fn (): int => $base()->where('status', TicketStatus::InProgress)->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::InProgress)),
            'rejected' => Tab::make('Needs update')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Rejected)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Rejected)),
            'with_approver' => Tab::make('With approver')
                ->badge(fn (): int => $base()
                    ->where('assigned_to_user_id', $userId)
                    ->whereNotNull('current_approver_user_id')
                    ->where('current_approver_user_id', '!=', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('assigned_to_user_id', $userId)
                    ->whereNotNull('current_approver_user_id')
                    ->where('current_approver_user_id', '!=', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])),
            'completed' => Tab::make('Completed')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Completed)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Completed)),
            'closed' => Tab::make('Closed')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Closed)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Closed)),
        ];
    }

    public function table(Table $table): Table
    {
        return TicketResource::table($table)
            ->query(function (): Builder {
                $query = $this->baseQuery()->latest('updated_at');

                return $this->modifyQueryWithActiveTab($query);
            })
            ->recordUrl(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]));
    }
}
