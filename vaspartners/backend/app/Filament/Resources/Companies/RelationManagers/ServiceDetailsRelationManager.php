<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenuePartner;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServiceDetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'revenuePartners';

    protected static ?string $title = 'Service details';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->revenuePartners()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Billing endpoints (revenue partners) linked to this company: service ID, short code, product ID, SPID and customer base.')
            ->modifyQueryUsing(fn ($query) => $query->with(['vasService']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('vasService.name')
                    ->label('Catalog service')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('service_id')
                    ->label('Service ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('short_code')
                    ->label('Short code')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('product_id')
                    ->label('Product ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('spid')
                    ->label('SPID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('customer_base_count')
                    ->label('Customer base')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('partner_name')
                    ->label('Partner name')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View')
                    ->url(fn (RevenuePartner $record): string => RevenuePartnerResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100]);
    }
}
