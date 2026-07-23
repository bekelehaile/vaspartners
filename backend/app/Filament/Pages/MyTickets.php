<?php

namespace App\Filament\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyTickets extends Page implements HasActions, HasSchemas, HasTable
{
    use HasPageShield;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'My Tickets';

    protected static ?string $title = 'My Tickets';

    protected static string|\UnitEnum|null $navigationGroup = 'Tickets';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'my-tickets';

    /**
     * @var view-string
     */
    protected string $view = 'filament.pages.my-tickets';

    public function getSubheading(): ?string
    {
        return 'Service requests assigned to you as account manager.';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Ticket::query()
            ->where('assigned_to_user_id', auth()->id())
            ->whereNotIn('status', [
                TicketStatus::Closed->value,
                TicketStatus::Completed->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return TicketResource::table($table)
            ->query(fn (): Builder => Ticket::query()
                ->where('assigned_to_user_id', auth()->id())
                ->latest('updated_at'))
            ->recordUrl(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(
                    fn (TicketStatus $s) => [$s->value => $s->label()]
                )),
            ]);
    }
}
