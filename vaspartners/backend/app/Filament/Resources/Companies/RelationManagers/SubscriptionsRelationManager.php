<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Subscriptions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->subscriptions()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Subscriptions belong to this company and stay with it when ownership transfers.')
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'contact']))
            ->columns([
                TextColumn::make('service.name')->label('Service')->searchable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => SubscriptionStatus::tryColor($state)),
                TextColumn::make('contact.name')->label('Activated by')->toggleable(),
                TextColumn::make('current_period_end')->dateTime()->sortable()->toggleable(),
                TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View')
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
