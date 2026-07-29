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

        // Approved outcomes use reviewed_at (not created_at).
        $approvedQuery = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Approved);
        $this->applyDashboardEventDateFilter($approvedQuery, 'reviewed_at', defaultToToday: true);
        $approvedLabel = $this->hasCustomDateRange() ? 'Approved in range' : 'Approved today';

        $pendingHint = $this->hasCustomDateRange() ? 'created_at in range' : 'Pending queue';

        return [
            Stat::make('Ownership transfers', $pendingTransfers)
                ->description($pendingHint.' — admin must decide')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color($pendingTransfers > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=ownership'),
            Stat::make('Membership joins', $pendingJoins)
                ->description($pendingHint.' — owner decides in portal')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color($pendingJoins > 0 ? 'info' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=membership'),
            Stat::make('Leave company', $pendingLeave)
                ->description($pendingHint.' — detach requests')
                ->descriptionIcon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color($pendingLeave > 0 ? 'warning' : 'gray')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=leave'),
            Stat::make($approvedLabel, $approvedQuery->count())
                ->description($this->hasCustomDateRange() ? 'reviewed_at in range' : 'reviewed_at today')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(CompanyChangeRequestResource::getUrl('index').'?tab=approved'),
        ];
    }
}
