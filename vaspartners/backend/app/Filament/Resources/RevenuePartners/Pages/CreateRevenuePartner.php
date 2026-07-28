<?php

namespace App\Filament\Resources\RevenuePartners\Pages;

use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRevenuePartner extends CreateRecord
{
    protected static string $resource = RevenuePartnerResource::class;

    public function getTitle(): string
    {
        return 'Create revenue partner';
    }

    public function getSubheading(): ?string
    {
        return 'Choose the company as Partner name, then set catalog service and service ID.';
    }
}
