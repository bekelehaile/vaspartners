<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Enums\TicketStatus;
use App\Models\TicketStatusHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status history';

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
            ->description('Full lifecycle trail of status changes with actor and timestamp.')
            ->modifyQueryUsing(fn ($query) => $query->with('actor')->oldest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
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
            ->paginated([10, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
