<?php

namespace App\Filament\Widgets;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class RevenueStatsOverview extends StatsOverviewWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Revenue';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return Gate::allows('ViewAny:RevenueImport');
    }

    protected function getStats(): array
    {
        $imports = RevenueImportResource::getEloquentQuery();
        $this->applyDashboardServiceFilter($imports, 'vas_service_id');

        $needsReview = (clone $imports)
            ->whereIn('status', [
                RevenueImportStatus::Draft,
                RevenueImportStatus::Reviewing,
                RevenueImportStatus::Ready,
            ])
            ->count();

        $latest = (clone $imports)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->first();

        $periodLabel = $latest?->period ?: 'No imports';
        $periodTotal = 0.0;
        $missingPhone = 0;

        if ($latest) {
            $rows = RevenueImportRow::query()->where('revenue_import_id', $latest->id);
            $periodTotal = (float) (clone $rows)
                ->whereIn('status', [RevenueImportRowStatus::Matched, RevenueImportRowStatus::Sent])
                ->sum('amount');
            $missingPhone = (clone $rows)
                ->where('status', RevenueImportRowStatus::MissingPhone)
                ->count();
        }

        return [
            Stat::make('Latest period', $this->formatEtb($periodTotal))
                ->description($periodLabel.' · matched & sent')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color($latest ? 'success' : 'gray')
                ->url($latest
                    ? RevenueImportResource::getUrl('view', ['record' => $latest])
                    : RevenueImportResource::getUrl('index')),
            Stat::make('Needs attention', $needsReview)
                ->description('Draft / review / ready to send')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($needsReview > 0 ? 'warning' : 'gray')
                ->url(RevenueImportResource::getUrl('index')),
            Stat::make('Missing phones', $missingPhone)
                ->description($latest ? $periodLabel.' rows without SMS phone' : '—')
                ->descriptionIcon(Heroicon::OutlinedPhoneXMark)
                ->color($missingPhone > 0 ? 'danger' : 'gray')
                ->url($latest
                    ? RevenueImportResource::getUrl('view', ['record' => $latest])
                    : RevenueImportResource::getUrl('index')),
        ];
    }

    protected function formatEtb(float $amount): string
    {
        return 'ETB '.number_format($amount, 0);
    }
}
