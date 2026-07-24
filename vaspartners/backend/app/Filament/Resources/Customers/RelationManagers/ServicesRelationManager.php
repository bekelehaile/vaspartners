<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'subscribedServices';

    protected static ?string $title = 'Services';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('renewal_interval')->badge(),
                IconColumn::make('is_subscription_based')->boolean()->label('Subs'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Configure service')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Model $record) => ServiceResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
