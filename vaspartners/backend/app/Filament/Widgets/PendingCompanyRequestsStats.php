<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\CompanyChangeRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingCompanyRequestsStats extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Change requests';

    protected ?string $description = 'Ownership, joins, and leave requests';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $base = CompanyChangeRequest::query();
        // Change requests are not service-scoped; date filter still applies.
        $this->applyDashboardDateFilter($base, 'created_at');

        $pendingTransfers = (clone $base)
            ->where('status', CompanyChangeStatus::Pending)
            ->where('type', CompanyChangeType::TransferOwnership)
            ->count();
        $pendingJoins = (clone $base)
            ->where('status', CompanyChangeStatus::Pending)
            ->where('type', CompanyChangeType::Attach)
            ->count();
        $pendingLeave = (clone $base)
            ->where('status', CompanyChangeStatus::Pending)
            ->where('type', CompanyChangeType::Detach)
            ->count();

        $approvedQuery = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Approved);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardDateFilter($approvedQuery, 'reviewed_at');
            $approvedLabel = 'Approved in range';
        } else {
            $approvedQuery->whereDate('reviewed_at', today());
            $approvedLabel = 'Approved today';
        }

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
            Stat::make('Leave company', $pendingLeave)
                ->description('Pending detach requests')
                ->descriptionIcon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color($pendingLeave > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=leave'),
            Stat::make($approvedLabel, $approvedQuery->count())
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=approved'),
        ];
    }
}
