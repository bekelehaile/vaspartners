<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyChangeStatus;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingCompanyRequestsStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Company requests';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $pending = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Pending)
            ->count();
        $approvedToday = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Approved)
            ->whereDate('reviewed_at', today())
            ->count();
        $rejectedToday = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Rejected)
            ->whereDate('reviewed_at', today())
            ->count();

        return [
            Stat::make('Pending', $pending)
                ->description('Awaiting admin decision')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index')),
            Stat::make('Approved today', $approvedToday)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make('Rejected today', $rejectedToday)
                ->descriptionIcon(Heroicon::OutlinedXCircle)
                ->color('danger'),
        ];
    }
}
