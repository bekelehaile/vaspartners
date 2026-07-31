<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        $base = fn (): Builder => SubscriptionResource::getEloquentQuery();

        return [
            'active' => Tab::make('Active')
                ->badge(fn (): int => $base()->where('status', SubscriptionStatus::Active)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Active)),
            'pending_renewal' => Tab::make('Pending renewal')
                ->badge(fn (): int => $base()->where('status', SubscriptionStatus::PendingRenewal)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::PendingRenewal)),
            'grace' => Tab::make('Grace')
                ->badge(fn (): int => $base()->where('status', SubscriptionStatus::Grace)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Grace)),
            'expired' => Tab::make('Expired')
                ->badge(fn (): int => $base()->where('status', SubscriptionStatus::Expired)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Expired)),
            'deactive' => Tab::make('Deactive')
                ->badge(fn (): int => $base()->where('status', SubscriptionStatus::Deactive)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Deactive)),
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
        ];
    }
}
