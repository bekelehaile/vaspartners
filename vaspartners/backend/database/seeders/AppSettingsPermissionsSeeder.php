<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Filament page permission for partner portal auth mode (App settings).
 * Super admin bypasses Gate; still create permission for Roles UI assignment.
 */
class AppSettingsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('View:ManageAppSettings', 'web');

        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        $this->command?->info('App settings page permission synced (View:ManageAppSettings).');
    }
}
