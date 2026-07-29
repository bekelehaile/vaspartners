<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CompanyStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Partners';

    protected ?string $description = 'Approvals and ownership that need attention';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tinAwaiting = Company::query()->awaitingTinApproval()->count();
        $pending = Company::query()
            ->where('approval_status', CompanyApprovalStatus::Pending)
            ->count();
        $orphans = Company::query()
            ->ownerless()
            ->where('approval_status', CompanyApprovalStatus::Approved)
            ->count();
        $approved = Company::query()
            ->where('approval_status', CompanyApprovalStatus::Approved)
            ->count();

        return [
            Stat::make('TIN awaiting', $tinAwaiting)
                ->description('Validate partner TIN')
                ->descriptionIcon(Heroicon::OutlinedIdentification)
                ->color($tinAwaiting > 0 ? 'warning' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=tin_awaiting'),
            Stat::make('Pending approval', $pending)
                ->description('New partner applications')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=pending'),
            Stat::make('Orphans', $orphans)
                ->description('Approved — no owner')
                ->descriptionIcon(Heroicon::OutlinedUserMinus)
                ->color($orphans > 0 ? 'danger' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=orphans'),
            Stat::make('Approved partners', $approved)
                ->description('Active partner companies')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice)
                ->color('success')
                ->url(CompanyResource::getUrl('index').'?tab=approved'),
        ];
    }
}
