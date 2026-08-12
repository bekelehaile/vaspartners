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
        return 'Partner name is from finance/Excel. Link a validated portal company — phone is taken from that company’s revenue phone.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! RevenuePartnerResource::viewerCanAccessAllRevenue()) {
            $data['created_by_user_id'] = auth()->id();
        }

        return $data;
    }
}
