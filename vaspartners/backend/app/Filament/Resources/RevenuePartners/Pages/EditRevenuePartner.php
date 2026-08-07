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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['company_id'])) {
            $company = \App\Models\Company::query()->find($data['company_id']);
            if ($company) {
                $data['phone'] = \App\Support\PhoneNumber::normalizeNullable($company->revenuePhone());
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! RevenuePartnerResource::viewerCanAccessAllRevenue()) {
            $data['created_by_user_id'] = auth()->id();
        }

        if (! empty($data['company_id'])) {
            $company = \App\Models\Company::query()->find($data['company_id']);
            if ($company) {
                $data['phone'] = \App\Support\PhoneNumber::normalizeNullable($company->revenuePhone());
            }
        }

        return $data;
    }
}