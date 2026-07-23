<?php

namespace App\Filament\Resources\CompanyChangeRequests\Pages;

use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCompanyChangeRequests extends ListRecords
{
    protected static string $resource = CompanyChangeRequestResource::class;
}
