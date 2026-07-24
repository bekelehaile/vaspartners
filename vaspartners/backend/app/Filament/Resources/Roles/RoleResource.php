<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Roles UI includes super_admin for now so permissions can be reviewed/edited.
 * Delete stays blocked; hide from the list again later when ready.
 */
class RoleResource extends ShieldRoleResource
{
    #[Override]
    public static function canDelete(Model $record): bool
    {
        return ! static::isSuperAdminRole($record) && parent::canDelete($record);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    public static function isSuperAdminRole(Model $record): bool
    {
        return (string) $record->getAttribute('name') === Utils::getSuperAdminName();
    }
}
