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
        // Pending change requests use created_at when a date range is set; otherwise live queue.
        $pendingBase = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Pending);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardDateFilter($pendingBase, 'created_at');
        }

        $pendingTransfers = (clone $pendingBase)
            ->where('type', CompanyChangeType::TransferOwnership)
            ->count();
        $pendingJoins = (clone $pendingBase)
            ->where('type', CompanyChangeType::Attach)
            ->count();
        $pendingLeave = (clone $pendingBase)
            ->where('type', CompanyChangeType::Detach)
            ->count();

        // Approved: live total when no dates; reviewed_at when a range is set.
        $approvedQuery = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Approved);
        if ($this->hasCustomDateRange()) {
            $this->applyDashboardEventDateFilter($approvedQuery, 'reviewed_at', defaultToToday: false);
        }
        $approvedLabel = 'Approved';

        return [
            Stat::make('Ownership transfers', $pendingTransfers)
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color($pendingTransfers > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=ownership'),
            Stat::make('Membership joins', $pendingJoins)
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color($pendingJoins > 0 ? 'info' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=membership'),
            Stat::make('Leave company', $pendingLeave)
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
