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
        return 'Import cleaned CSV (billing service_id + revenue). Choose an existing catalog service + month. Unresolved rows are flagged for edit.';
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        if (! $user?->can('Create:RevenueImport') && ! $user?->canAccessAllRevenue()) {
            // AMs get Create via seeder; still show if they manage a family
            if (! $user || ($user->managedRevenueServiceIds() === [] && ! $user->canAccessAllRevenue())) {
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
