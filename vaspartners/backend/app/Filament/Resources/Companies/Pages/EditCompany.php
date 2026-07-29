<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\User;
use App\Services\CompanyMembershipService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /** @var array{approval_status?: mixed, is_active?: mixed, tin_validated?: mixed}|null */
    protected ?array $conditionSnapshot = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        /** @var \App\Models\Company $company */
        $company = $this->getRecord();
        $this->conditionSnapshot = [
            'approval_status' => $company->approval_status instanceof \BackedEnum
                ? $company->approval_status->value
                : $company->approval_status,
            'is_active' => (bool) $company->is_active,
            'tin_validated' => (bool) $company->tin_validated,
        ];
    }

    protected function afterSave(): void
    {
        /** @var \App\Models\Company $company */
        $company = $this->getRecord();
        $membership = app(CompanyMembershipService::class);
        $membership->syncAllMembersDenormalizedFields($company);

        if ($this->conditionSnapshot !== null) {
            $admin = auth()->user();
            $membership->logAdminConditionChanges(
                $company,
                $this->conditionSnapshot,
                $admin instanceof User ? $admin : null,
                'Updated from company edit form',
            );
            $this->conditionSnapshot = null;
        }

        if ($company->wasChanged('is_active') && ! $company->is_active) {
            $membership->revokePortalAccessForInactiveCompany($company);
        }
    }
}
