<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingCompanyRequestsStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Change requests';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $pendingTransfers = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Pending)
            ->where('type', CompanyChangeType::TransferOwnership)
            ->count();
        $pendingJoins = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Pending)
            ->where('type', CompanyChangeType::Attach)
            ->count();
        $approvedToday = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Approved)
            ->whereDate('reviewed_at', today())
            ->count();

        return [
            Stat::make('Ownership transfers', $pendingTransfers)
                ->description('Pending — admin must decide')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color($pendingTransfers > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=ownership'),
            Stat::make('Membership joins', $pendingJoins)
                ->description('Pending — owner decides in portal')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color($pendingJoins > 0 ? 'info' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=membership'),
            Stat::make('Approved today', $approvedToday)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=approved'),
        ];
    }
}
