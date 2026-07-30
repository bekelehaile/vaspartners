<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyStatusHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status log';

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
            ->description('ERCA TIN number confirmation and Active changes.')
            ->modifyQueryUsing(fn ($query) => $query->with(['actorUser', 'actorContact']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actor')
                    ->label('By')
                    ->state(function (CompanyStatusHistory $record): string {
                        if ($record->actorUser) {
                            return $record->actorUser->name
                                ?: ($record->actorUser->email ?? 'Staff #'.$record->actor_user_id);
                        }
                        if ($record->actorContact) {
                            return ($record->actorContact->name ?? 'Partner')
                                .' (partner)';
                        }

                        return 'System / ERCA';
                    }),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (CompanyStatusHistory $record): string => $record->actionLabel())
                    ->color(fn (CompanyStatusHistory $record): string => match ($record->action) {
                        'approved', 'tin_validated', 'activated' => 'success',
                        'rejected', 'deactivated' => 'danger',
                        'tin_cleared' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                IconColumn::make('tin_validated')
                    ->label('TIN number verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
