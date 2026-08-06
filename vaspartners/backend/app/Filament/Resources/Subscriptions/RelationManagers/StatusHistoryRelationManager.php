<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketStatusHistory;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['actor', 'ticket']))
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
                    ->toggleable()
                    ->tooltip(fn (TicketStatusHistory $record): ?string => mb_strlen((string) $record->note) > 80
                        ? 'Open View for the full note'
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (TicketStatusHistory $record): string => sprintf(
                        'Log · %s',
                        $record->created_at?->format('M j, Y H:i') ?? '—',
                    ))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        TextInput::make('request')
                            ->label('Request number')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('by')
                            ->label('By')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('from_status')
                            ->label('From')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('to_status')
                            ->label('To')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(10)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->fillForm(function (TicketStatusHistory $record): array {
                        $actor = $record->actor;

                        return [
                            'request' => $record->ticket?->tt_number ?: '—',
                            'by' => $actor?->name
                                ?? ($actor ? class_basename($actor::class).' #'.$actor->getKey() : 'System'),
                            'from_status' => TicketStatus::tryFrom((string) $record->from_status)?->label()
                                ?? ($record->from_status ?: '—'),
                            'to_status' => TicketStatus::tryFrom((string) $record->to_status)?->label()
                                ?? ($record->to_status ?: '—'),
                            'note' => filled($record->note) ? (string) $record->note : '—',
                        ];
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No status logs yet')
            ->emptyStateDescription('Lifecycle events from linked tickets will appear here.')
            ->paginated([10, 25, 50]);
    }
}
