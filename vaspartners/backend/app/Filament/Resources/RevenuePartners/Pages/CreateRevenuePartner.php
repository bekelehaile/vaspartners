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
        return 'Partner name is from finance/Excel. Optionally link a validated portal company for phone / membership.';
    }
}
