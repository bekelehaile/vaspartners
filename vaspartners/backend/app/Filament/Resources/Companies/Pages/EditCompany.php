<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Services\CompanyMembershipService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var \App\Models\Company $company */
        $company = $this->getRecord();
        $membership = app(CompanyMembershipService::class);
        $membership->syncAllMembersDenormalizedFields($company);

        if ($company->wasChanged('is_active') && ! $company->is_active) {
            $membership->revokePortalAccessForInactiveCompany($company);
        }
    }
}
