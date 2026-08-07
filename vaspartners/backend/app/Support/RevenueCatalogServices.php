<?php

namespace App\Support;

use App\Models\Service;
use Filament\Forms\Components\Select;
use Illuminate\Support\Collection;

final class RevenueCatalogServices
{
    /**
     * Services flagged for Monthly Revenue (`has_monthly_revenue`).
     * Includes inactive services — inactive only blocks new subscriptions.
     *
     * @return Collection<int, Service>
     */
    public static function query(): Collection
    {
        return Service::query()
            ->withMonthlyRevenue()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()
            ->mapWithKeys(fn (Service $service) => [$service->id => (string) $service->name])
            ->all();
    }

    public static function importSelect(string $helperText = ''): Select
    {
        return Select::make('vas_service_id')
            ->label('Catalog service')
            ->options(fn (): array => self::options())
            ->required()
            ->searchable()
            ->preload()
            ->native(false)
            ->helperText($helperText !== ''
                ? $helperText
                : 'Services with Monthly revenue enabled (active or inactive).');
    }
}
