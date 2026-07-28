<?php

namespace App\Filament\Resources\RevenueImports\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImportRow;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Import rows';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                /** @var \App\Models\User|null $user */
                $user = auth()->user();
                if (! $user || $user->canAccessAllRevenue()) {
                    return $query;
                }
                $families = $user->managedRevenueFamilyValues();

                return $families === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('service_family', $families);
            })
            ->columns([
                TextColumn::make('service_family')
                    ->label('Family')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\RevenueServiceFamily ? $state->label() : (string) $state)
                    ->color(fn ($state) => ($state instanceof \App\Enums\RevenueServiceFamily
                        ? $state
                        : \App\Enums\RevenueServiceFamily::tryFrom((string) $state))?->color() ?? 'gray')
                    ->toggleable(),
                TextColumn::make('row_number')->label('Row')->toggleable(),
                TextColumn::make('service_id')->label('Service ID')->searchable()->copyable(),
                TextColumn::make('partner_name')->searchable()->wrap(),
                TextColumn::make('service_type')->toggleable()->placeholder('—'),
                TextColumn::make('amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                        ? $state
                        : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('partner.phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('error')->wrap()->toggleable()->limit(80),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('service_family')
                    ->label('Family')
                    ->options(\App\Enums\RevenueServiceFamily::options()),
            ])
            ->recordActions([
                Action::make('open_partner')
                    ->label('Partner')
                    ->icon('heroicon-o-identification')
                    ->visible(fn (RevenueImportRow $record): bool => filled($record->revenue_partner_id))
                    ->url(fn (RevenueImportRow $record): ?string => $record->partner
                        ? RevenuePartnerResource::getUrl('view', ['record' => $record->partner])
                        : null),
            ])
            ->paginated([25, 50, 100]);
    }
}
