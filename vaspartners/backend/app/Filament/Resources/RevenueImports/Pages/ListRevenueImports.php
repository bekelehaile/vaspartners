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
        return 'Monthly revenue only — import a CSV (service ID + amount), match partners, then send revenue-collection SMS. General / special announcements use Bulk messages.';
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        if (! $user?->can('Create:RevenueImport') && ! $user?->canAccessAllRevenue()) {
            return [];
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
