<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Service requests';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->tickets()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Requests linked to this subscription (activation, renewals, upgrades, and other manage flows). Open a ticket for messaging and document review.')
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'requisition', 'contact']))
            ->columns([
                TextColumn::make('tt_number')->label('Request number')->searchable()->sortable(),
                TextColumn::make('requisition.name')->label('Type')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TicketStatus
                        ? $state->label()
                        : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => match ($state instanceof TicketStatus ? $state : TicketStatus::tryFrom((string) $state)) {
                        TicketStatus::Completed, TicketStatus::Closed => 'success',
                        TicketStatus::Rejected => 'danger',
                        TicketStatus::InProgress => 'info',
                        TicketStatus::Open => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('contact.name')->label('Partner')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(TicketStatus::cases())->mapWithKeys(
                        fn (TicketStatus $s) => [$s->value => $s->label()]
                    )->all()
                ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Open')
                    ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No service requests yet')
            ->emptyStateDescription('When partners open renewals or other requests on this subscription, they appear here.');
    }
}
