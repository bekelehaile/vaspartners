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

    protected ?string $heading = 'Company & membership requests';

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
            Stat::make('Transfers to decide', $pendingTransfers)
                ->description('Admin must approve ownership transfers')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color($pendingTransfers > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index', [
                    'tableFilters' => [
                        'type' => ['value' => CompanyChangeType::TransferOwnership->value],
                        'status' => ['value' => CompanyChangeStatus::Pending->value],
                    ],
                ])),
            Stat::make('Joins awaiting owner', $pendingJoins)
                ->description('Partners decide these in the portal inbox')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color($pendingJoins > 0 ? 'info' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index')),
            Stat::make('Approved today', $approvedToday)
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
        ];
    }
}
