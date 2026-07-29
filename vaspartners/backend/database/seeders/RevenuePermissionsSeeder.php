<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style permissions for RevenuePartner + RevenueImport.
 * - super_admin / management admin: full access (unscoped)
 * - account_manager (operational): view/update own partners + own imports (Filament scopes by created_by_user_id)
 */
class RevenuePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $abilities = [
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
        ];

        $allNames = [];
        foreach (['RevenuePartner', 'RevenueImport'] as $resource) {
            foreach ($abilities as $ability) {
                $allNames[] = Permission::findOrCreate("{$ability}:{$resource}", 'web')->name;
            }
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->givePermissionTo($allNames);

        $admin = Role::findOrCreate('admin', 'web');
        $admin->givePermissionTo($allNames);

        $amPerms = [
            'ViewAny:RevenuePartner',
            'View:RevenuePartner',
            'Create:RevenuePartner',
            'Update:RevenuePartner',
            'ViewAny:RevenueImport',
            'View:RevenueImport',
            'Create:RevenueImport',
            'Update:RevenueImport',
            'Delete:RevenueImport',
        ];
        foreach ($amPerms as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $accountManager = Role::findOrCreate('account_manager', 'web');
        $accountManager->givePermissionTo($amPerms);
    }
}
