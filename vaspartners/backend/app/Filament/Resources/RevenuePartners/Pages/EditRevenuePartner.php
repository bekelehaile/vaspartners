<?php

namespace App\Filament\Resources\RevenuePartners\Pages;

use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRevenuePartner extends EditRecord
{
    protected static string $resource = RevenuePartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->url(fn (): string => RevenuePartnerResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
