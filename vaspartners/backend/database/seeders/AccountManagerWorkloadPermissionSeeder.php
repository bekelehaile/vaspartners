<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reports → Account handler workload (live status counts).
 */
class AccountManagerWorkloadPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('View:AccountManagerWorkload', 'web');

        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        $this->command?->info('Permission View:AccountManagerWorkload granted to super_admin and admin.');
    }
}
