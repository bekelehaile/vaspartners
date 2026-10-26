<?php

namespace App\Filament\Pages;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ProvisioningDashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Provisioning';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Provisioning dashboard';

    protected string $view = 'filament.pages.provisioning-dashboard';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $byStatus = ServiceProvisioning::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $waitingByDept = ServiceProvisioningStep::query()
            ->where('status', ProvisioningStepStatus::Waiting->value)
            ->select('to_department', DB::raw('count(*) as aggregate'))
            ->groupBy('to_department')
            ->pluck('aggregate', 'to_department');

        $open = ServiceProvisioning::query()
            ->whereIn('status', [
                ProvisioningStatus::InProgress->value,
                ProvisioningStatus::Blocked->value,
                ProvisioningStatus::Draft->value,
            ])
            ->with(['company', 'service'])
            ->latest('id')
            ->limit(8)
            ->get();

        $aging = [
            '0-3' => 0,
            '4-7' => 0,
            '8+' => 0,
        ];
        ServiceProvisioning::query()
            ->whereIn('status', [
                ProvisioningStatus::InProgress->value,
                ProvisioningStatus::Blocked->value,
            ])
            ->get()
            ->each(function (ServiceProvisioning $case) use (&$aging): void {
                $days = $case->overallElapsedDays();
                if ($days <= 3) {
                    $aging['0-3']++;
                } elseif ($days <= 7) {
                    $aging['4-7']++;
                } else {
                    $aging['8+']++;
                }
            });

        return [
            'byStatus' => $byStatus,
            'waitingByDept' => $waitingByDept,
            'aging' => $aging,
            'recent' => $open,
        ];
    }
}
