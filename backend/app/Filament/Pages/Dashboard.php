<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendingCompanyRequestsStats;
use App\Filament\Widgets\SubscriptionStatsOverview;
use App\Filament\Widgets\TicketStatsOverview;
use App\Filament\Widgets\TicketsByStatusChart;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -10;

    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            TicketStatsOverview::class,
            SubscriptionStatsOverview::class,
            PendingCompanyRequestsStats::class,
            TicketsByStatusChart::class,
        ];
    }
}
