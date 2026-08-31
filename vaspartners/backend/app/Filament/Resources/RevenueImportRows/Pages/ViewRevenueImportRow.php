<?php

namespace App\Filament\Resources\RevenueImportRows\Pages;

use App\Filament\Resources\RevenueImportRows\RevenueImportRowResource;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImportRow;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewRevenueImportRow extends ViewRecord
{
    protected static string $resource = RevenueImportRowResource::class;

    protected function getHeaderActions(): array
    {
        /** @var RevenueImportRow $record */
        $record = $this->getRecord();

        return array_values(array_filter([
            $record->import
                ? Action::make('open_import')
                    ->label('Open monthly import')
                    ->icon('heroicon-o-banknotes')
                    ->url(RevenueImportResource::getUrl('view', ['record' => $record->import]))
                : null,
            $record->revenue_partner_id
                ? Action::make('open_partner')
                    ->label('Open master partner')
                    ->icon('heroicon-o-identification')
                    ->url(RevenuePartnerResource::getUrl('view', ['record' => $record->revenue_partner_id]))
                : null,
        ]));
    }
}
