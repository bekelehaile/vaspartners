<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Subscriptions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('service:id,name'))
            ->columns([
                TextColumn::make('service.name')->label('Service')->searchable()->wrap(),
                TextColumn::make('status')->badge(),
                TextColumn::make('renewal_interval')->badge(),
                TextColumn::make('current_period_end')->dateTime()->sortable()->toggleable(),
                TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
            ])
            ->recordActions([
                Action::make('open_subscription')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => SubscriptionResource::getUrl('view', ['record' => $record])),
                Action::make('open_service')
                    ->label('Open service')
                    ->icon('heroicon-o-puzzle-piece')
                    ->url(fn ($record) => $record->service
                        ? ServiceResource::getUrl('edit', ['record' => $record->service])
                        : null)
                    ->visible(fn ($record) => filled($record->service_id)),
            ]);
    }
}
