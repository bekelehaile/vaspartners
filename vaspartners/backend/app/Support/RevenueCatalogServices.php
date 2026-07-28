<?php

namespace App\Support;

use App\Models\Service;
use Filament\Forms\Components\Select;
use Illuminate\Support\Collection;

final class RevenueCatalogServices
{
    /**
     * Active catalog services available for revenue mapping.
     *
     * @return Collection<int, Service>
     */
    public static function query(): Collection
    {
        return Service::query()
            ->where('is_active', true)
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
            ->mapWithKeys(fn (Service $service) => [$service->id => $service->name])
            ->all();
    }

    public static function importSelect(string $helperText = ''): Select
    {
        return Select::make('vas_service_id')
            ->label('Catalog service')
            ->options(self::options())
            ->required()
            ->searchable()
            ->native(false)
            ->helperText($helperText !== ''
                ? $helperText
                : 'Existing portal service this revenue belongs to.');
    }
}
