<?php

namespace App\Filament\Resources\RevenueImportRows\Pages;

use App\Filament\Resources\RevenueImportRows\RevenueImportRowResource;
use Filament\Resources\Pages\ListRecords;

class ListRevenueImportRows extends ListRecords
{
    protected static string $resource = RevenueImportRowResource::class;

    public function getSubheading(): ?string
    {
        return 'Search individual partner amounts across all monthly revenue imports.';
    }
}
