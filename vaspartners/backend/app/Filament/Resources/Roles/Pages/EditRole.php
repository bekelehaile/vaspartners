<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use BezhanSalleh\FilamentShield\Support\Utils;

class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (RoleResource::isSuperAdminRole($this->getRecord())) {
            abort(403, 'The super_admin role cannot be edited.');
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['name'] ?? null) === Utils::getSuperAdminName()
            || RoleResource::isSuperAdminRole($this->getRecord())) {
            abort(403, 'The super_admin role cannot be edited.');
        }

        return parent::mutateFormDataBeforeSave($data);
    }
}
