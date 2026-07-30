<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CompanyStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Partners';

    protected ?string $description = 'TIN verification that needs attention';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tinOk = Company::query()->tinApproved()->count();
        $tinAwaiting = Company::query()->awaitingTinApproval()->count();
        $mismatch = Company::query()->ercaNameMismatchPending()->count();
        $invalid = Company::query()->invalidOrMissingTin()->count();

        return [
            Stat::make('Verified', $tinOk)
                ->description('TIN confirmed')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice)
                ->color('success')
                ->url(CompanyResource::getUrl('index').'?tab=tin_ok'),
            Stat::make('Awaiting verification', $tinAwaiting)
                ->description('Valid TIN — pending check')
                ->descriptionIcon(Heroicon::OutlinedIdentification)
                ->color($tinAwaiting > 0 ? 'warning' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=tin_awaiting'),
            Stat::make('Name mismatch', $mismatch)
                ->description('Partner consent needed')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($mismatch > 0 ? 'warning' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=mismatch'),
            Stat::make('Invalid TIN', $invalid)
                ->description('Missing or not 10 digits')
                ->descriptionIcon(Heroicon::OutlinedXCircle)
                ->color($invalid > 0 ? 'danger' : 'gray')
                ->url(CompanyResource::getUrl('index').'?tab=invalid_tin'),
        ];
    }
}
