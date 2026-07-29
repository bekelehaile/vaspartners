<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyApprovalStatus;
use App\Models\CompanyStatusHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Approval & TIN NUMBER log';

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
            ->description('Who approved or cleared TIN NUMBER, changed approval/Active — and when.')
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

                        return 'System';
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
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CompanyApprovalStatus::tryFrom((string) $state)?->label()
                        ?? ($state ?: '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                IconColumn::make('tin_validated')
                    ->label('TIN NUMBER OK')
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
