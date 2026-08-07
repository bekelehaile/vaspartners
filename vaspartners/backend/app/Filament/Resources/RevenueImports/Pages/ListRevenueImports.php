<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Filament\Imports\MonthlyRevenueImporter;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenueImports extends ListRecords
{
    protected static string $resource = RevenueImportResource::class;

    public function getSubheading(): ?string
    {
        return 'Monthly revenue — build from your Revenue Partners list, or import a finance CSV (service ID + amount), then send collection SMS.';
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        if (! $user?->can('Create:RevenueImport') && ! $user?->canAccessAllRevenue()) {
            return [];
        }

        return [
            Action::make('compose_from_partners')
                ->label('From my partners')
                ->icon('heroicon-o-user-group')
                ->color('primary')
                ->url(RevenueImportResource::getUrl('compose')),
            ImportAction::make()
                ->importer(MonthlyRevenueImporter::class)
                ->label('Import monthly CSV')
                ->color('gray')
                ->maxRows(20000)
                ->chunkSize(100),
        ];
    }
}
