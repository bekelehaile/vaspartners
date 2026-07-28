<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Filament\Imports\MonthlyRevenueImporter;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenueImports extends ListRecords
{
    protected static string $resource = RevenueImportResource::class;

    public function getSubheading(): ?string
    {
        return 'Import one cleaned CSV (service_id, short_code, revenue). Select service family + month. Validate, then send SMS. Scoped by who imported and assigned family.';
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        if (! $user?->can('Create:RevenueImport') && ! $user?->canAccessAllRevenue()) {
            // AMs get Create via seeder; still show if they manage a family
            if (! $user || ($user->managedRevenueFamilyValues() === [] && ! $user->canAccessAllRevenue())) {
                return [];
            }
        }

        return [
            ImportAction::make()
                ->importer(MonthlyRevenueImporter::class)
                ->label('Import monthly CSV')
                ->color('primary')
                ->maxRows(20000)
                ->chunkSize(100),
        ];
    }
}
