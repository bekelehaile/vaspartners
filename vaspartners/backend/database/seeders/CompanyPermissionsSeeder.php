<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style Company permissions.
 * Other roles: assign SendSms / SendSmsAny via Roles UI.
 * Super admin: always granted those SMS abilities here.
 */
class CompanyPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'ViewAny',
            'View',
            'Create',
            'Update',
            'Delete',
            'DeleteAny',
            'Restore',
            'RestoreAny',
            'ForceDelete',
            'ForceDeleteAny',
            'Replicate',
            'Reorder',
            'SendSms',
            'SendSmsAny',
        ] as $ability) {
            Permission::findOrCreate("{$ability}:Company", 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->givePermissionTo([
            'SendSms:Company',
            'SendSmsAny:Company',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
