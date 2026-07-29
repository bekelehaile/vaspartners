<?php

namespace App\Filament\Resources\RevenuePartners\Pages;

use App\Filament\Imports\RevenuePartnerImporter;
use App\Filament\Imports\RevenuePartnerPhoneImporter;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenuePartners extends ListRecords
{
    protected static string $resource = RevenuePartnerResource::class;

    public function getSubheading(): ?string
    {
        return 'Partner name comes from finance/Excel. Optionally link a validated portal company. Monthly CSV matches service ID / short code.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make('syncPhones')
                ->importer(RevenuePartnerPhoneImporter::class)
                ->label('Sync phones')
                ->color('gray')
                ->authorize(fn (): bool => (bool) auth()->user()?->can('Update:RevenuePartner')),
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
