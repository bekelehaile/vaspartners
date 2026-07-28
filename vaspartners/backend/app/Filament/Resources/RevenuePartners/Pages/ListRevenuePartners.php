<?php

namespace App\Filament\Resources\RevenuePartners\Pages;

use App\Filament\Imports\RevenuePartnerImporter;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenuePartners extends ListRecords
{
    protected static string $resource = RevenuePartnerResource::class;

    public function getSubheading(): ?string
    {
        return 'Import partners that belong to you. Monthly CSV matches service ID / short code in your list (not by service ownership).';
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(RevenuePartnerImporter::class)
                ->label('Import CSV')
                ->color('gray')
                ->authorize(fn (): bool => (bool) auth()->user()?->can('Create:RevenuePartner')
                    || (bool) auth()->user()?->can('Update:RevenuePartner')),
            CreateAction::make(),
        ];
    }
}
