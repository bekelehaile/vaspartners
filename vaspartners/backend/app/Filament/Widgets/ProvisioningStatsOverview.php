<?php

namespace App\Filament\Widgets;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProvisioningStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Service provision';

    protected ?string $description = 'Live activation pipeline (service filter applies)';

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        $base = ServiceProvisioning::query();
        $this->applyDashboardServiceFilter($base);

        $openStatuses = [
            ProvisioningStatus::Draft->value,
            ProvisioningStatus::InProgress->value,
            ProvisioningStatus::Blocked->value,
        ];

        $open = (clone $base)->whereIn('status', $openStatuses)->count();
        $inProgress = (clone $base)->where('status', ProvisioningStatus::InProgress)->count();
        $blocked = (clone $base)->where('status', ProvisioningStatus::Blocked)->count();
        $goLive = (clone $base)->where('status', ProvisioningStatus::GoLive)->count();

        $waitingPartner = ServiceProvisioningStep::query()
            ->where('status', ProvisioningStepStatus::Waiting->value)
            ->where('to_department', ProvisioningDepartment::Partner->value)
            ->whereHas('provisioning', function ($q): void {
                $this->applyDashboardServiceFilter($q);
                $q->whereIn('status', [
                    ProvisioningStatus::Draft->value,
                    ProvisioningStatus::InProgress->value,
                    ProvisioningStatus::Blocked->value,
                ]);
            })
            ->count();

        $waitingStaff = ServiceProvisioningStep::query()
            ->where('status', ProvisioningStepStatus::Waiting->value)
            ->where('to_department', '!=', ProvisioningDepartment::Partner->value)
            ->whereHas('provisioning', function ($q): void {
                $this->applyDashboardServiceFilter($q);
                $q->whereIn('status', [
                    ProvisioningStatus::Draft->value,
                    ProvisioningStatus::InProgress->value,
                    ProvisioningStatus::Blocked->value,
                ]);
            })
            ->count();

        $agingOver8 = (clone $base)
            ->whereIn('status', [
                ProvisioningStatus::InProgress->value,
                ProvisioningStatus::Blocked->value,
            ])
            ->get()
            ->filter(fn (ServiceProvisioning $case): bool => $case->overallElapsedDays() >= 8)
            ->count();

        $indexUrl = ServiceProvisioningResource::getUrl('index');

        return [
            Stat::make('Open cases', $open)
                ->description('Draft / in progress / blocked')
                ->descriptionIcon(Heroicon::OutlinedRocketLaunch)
                ->color($open > 0 ? 'warning' : 'gray')
                ->url($indexUrl),
            Stat::make('In progress', $inProgress)
                ->description('Active handoffs')
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->color($inProgress > 0 ? 'primary' : 'gray')
                ->url($indexUrl),
            Stat::make('Blocked', $blocked)
                ->description('Needs attention')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($blocked > 0 ? 'danger' : 'gray')
                ->url($indexUrl),
            Stat::make('Waiting partner', $waitingPartner)
                ->description('Partner action required')
                ->descriptionIcon(Heroicon::OutlinedUser)
                ->color($waitingPartner > 0 ? 'warning' : 'gray')
                ->url($indexUrl),
            Stat::make('Waiting staff', $waitingStaff)
                ->description('Internal sections')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice)
                ->color($waitingStaff > 0 ? 'warning' : 'gray')
                ->url($indexUrl),
            Stat::make('Aging 8+ days', $agingOver8)
                ->description('Open cases running long')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($agingOver8 > 0 ? 'danger' : 'gray')
                ->url($indexUrl),
            Stat::make('Go Live', $goLive)
                ->description('Activation completed')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url($indexUrl),
        ];
    }
}
