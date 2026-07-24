<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Subscriptions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Subscriptions belong to this company and stay with it when ownership transfers.')
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'customer'])->latest('id'))
            ->columns([
                TextColumn::make('public_id')->label('ID')->searchable(),
                TextColumn::make('service.name')->label('Service')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus
                        ? $state->value
                        : (string) $state),
                TextColumn::make('customer.name')->label('Activated by')->toggleable(),
                TextColumn::make('current_period_end')->dateTime()->sortable(),
                TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
