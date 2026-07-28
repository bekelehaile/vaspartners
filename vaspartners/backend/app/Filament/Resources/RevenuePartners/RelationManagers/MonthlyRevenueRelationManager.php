<?php

namespace App\Filament\Resources\RevenuePartners\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Models\RevenueImportRow;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MonthlyRevenueRelationManager extends RelationManager
{
    protected static string $relationship = 'importRows';

    protected static ?string $title = 'Monthly revenue';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import.period')
                    ->label('Period')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('vasService.name')
                    ->label('Catalog service')
                    ->toggleable(),
                TextColumn::make('import.title')
                    ->label('Import')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('import.status')
                    ->label('Import status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Row status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                        ? $state
                        : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('import.imported_at')
                    ->label('Imported')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('error')->wrap()->toggleable(isToggledHiddenByDefault: true)->limit(80),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Row status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('open_import')
                    ->label('Open import')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn (RevenueImportRow $record): ?string => $record->import
                        ? RevenueImportResource::getUrl('view', ['record' => $record->import])
                        : null),
            ])
            ->paginated([25, 50, 100]);
    }
}
