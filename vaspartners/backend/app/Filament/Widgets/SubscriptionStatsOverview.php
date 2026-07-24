<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\Subscription;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubscriptionStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Subscriptions';

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        $base = Subscription::query();
        $this->applyDashboardServiceFilter($base);
        $this->applyDashboardDateFilter($base, 'started_at');

        $active = (clone $base)->where('status', SubscriptionStatus::Active)->count();
        $pendingRenewal = (clone $base)->where('status', SubscriptionStatus::PendingRenewal)->count();
        $dueSoon = (clone $base)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PendingRenewal, SubscriptionStatus::Grace])
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<=', now()->addDays(30))
            ->count();

        return [
            Stat::make('Active', $active)
                ->description('Live entitlements')
                ->descriptionIcon(Heroicon::OutlinedBolt)
                ->color('success')
                ->url(SubscriptionResource::getUrl('index')),
            Stat::make('Pending renewal', $pendingRenewal)
                ->description('Renewal in flight')
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->url(SubscriptionResource::getUrl('index')),
            Stat::make('Due in 30 days', $dueSoon)
                ->description('Period ending soon')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('danger')
                ->url(SubscriptionResource::getUrl('index')),
        ];
    }
}
