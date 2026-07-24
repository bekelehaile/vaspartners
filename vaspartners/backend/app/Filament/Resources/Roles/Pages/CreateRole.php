<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Validation\ValidationException;
use Override;

class CreateRole extends ShieldCreateRole
{
    protected static string $resource = RoleResource::class;

    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['name'] ?? null) === Utils::getSuperAdminName()) {
            throw ValidationException::withMessages([
                'name' => 'The super_admin role is reserved and cannot be created or managed here.',
            ]);
        }

        return parent::mutateFormDataBeforeCreate($data);
    }
}
