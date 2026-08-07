<?php

namespace App\Filament\Widgets;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\RevenueImportRow;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class RevenueStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Monthly revenue';

    protected ?string $description = 'Totals for your latest revenue month. Click a card to open Monthly revenue.';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        $user = auth()->user();

        return Gate::allows('ViewAny:RevenueImport')
            || (bool) ($user && method_exists($user, 'canAccessAllRevenue') && $user->canAccessAllRevenue());
    }

    protected function getStats(): array
    {
        $imports = RevenueImportResource::getEloquentQuery();
        $this->applyDashboardServiceFilter($imports, 'vas_service_id');

        $latestImport = (clone $imports)
            ->orderByDesc('id')
            ->first();

        $period = $latestImport?->period;
        $periodImports = (clone $imports);
        if (filled($period)) {
            $periodImports->where('period', $period);
        } else {
            $periodImports->whereRaw('1 = 0');
        }

        $periodImportIds = (clone $periodImports)->pluck('id');

        $rows = RevenueImportRow::query()->whereIn('revenue_import_id', $periodImportIds);

        $sentCount = (clone $rows)->where('status', RevenueImportRowStatus::Sent)->count();
        $sentAmount = (float) (clone $rows)->where('status', RevenueImportRowStatus::Sent)->sum('amount');

        $readyCount = (clone $rows)->where('status', RevenueImportRowStatus::Matched)->count();
        $readyAmount = (float) (clone $rows)->where('status', RevenueImportRowStatus::Matched)->sum('amount');

        $blockedCount = (clone $rows)
            ->whereIn('status', [
                RevenueImportRowStatus::MissingPhone,
                RevenueImportRowStatus::MissingPartner,
                RevenueImportRowStatus::Invalid,
                RevenueImportRowStatus::Duplicate,
            ])
            ->count();

        $openImports = (clone $periodImports)
            ->whereIn('status', [
                RevenueImportStatus::Draft,
                RevenueImportStatus::Reviewing,
                RevenueImportStatus::Ready,
                RevenueImportStatus::Sending,
            ])
            ->count();

        $periodLabel = filled($period) ? (string) $period : 'No imports yet';
        $indexUrl = RevenueImportResource::getUrl('index');

        return [
            Stat::make('SMS sent', $this->formatEtb($sentAmount))
                ->description($periodLabel.' · '.$sentCount.' partner'.($sentCount === 1 ? '' : 's'))
                ->descriptionIcon(Heroicon::OutlinedPaperAirplane)
                ->color($sentCount > 0 ? 'success' : 'gray')
                ->url($indexUrl),
            Stat::make('Ready to send', $this->formatEtb($readyAmount))
                ->description($readyCount.' partner'.($readyCount === 1 ? '' : 's').' matched')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color($readyCount > 0 ? 'info' : 'gray')
                ->url($indexUrl),
            Stat::make('Needs fixing', $blockedCount)
                ->description('Missing phone / partner / invalid')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($blockedCount > 0 ? 'danger' : 'gray')
                ->url($indexUrl),
            Stat::make('Open imports', $openImports)
                ->description($periodLabel.' · draft / review / ready')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentList)
                ->color($openImports > 0 ? 'warning' : 'gray')
                ->url($indexUrl),
        ];
    }

    protected function formatEtb(float $amount): string
    {
        return 'ETB '.number_format($amount, 0);
    }
}
