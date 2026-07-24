<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketStatusHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Logs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->statusHistories()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Status change history across all tickets on this subscription.')
            ->modifyQueryUsing(fn ($query) => $query->with(['actor', 'ticket'])->oldest('ticket_status_histories.id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ticket.tt_number')
                    ->label('Request number')
                    ->url(fn (TicketStatusHistory $record): ?string => $record->ticket
                        ? TicketResource::getUrl('view', ['record' => $record->ticket])
                        : null)
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('actor_name')
                    ->label('By')
                    ->state(function (TicketStatusHistory $record): string {
                        $actor = $record->actor;
                        if (! $actor) {
                            return 'System';
                        }

                        return $actor->name ?? class_basename($actor::class).' #'.$actor->getKey();
                    }),
                TextColumn::make('from_status')
                    ->label('From')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TicketStatus::tryFrom((string) $state)?->label() ?? ($state ?: '—'))
                    ->color('gray'),
                TextColumn::make('to_status')
                    ->label('To')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TicketStatus::tryFrom((string) $state)?->label() ?? ($state ?: '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed', 'closed' => 'success',
                        'rejected' => 'danger',
                        'in_progress' => 'info',
                        'open' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No status logs yet')
            ->emptyStateDescription('Lifecycle events from linked tickets will appear here.')
            ->paginated([10, 25, 50]);
    }
}
