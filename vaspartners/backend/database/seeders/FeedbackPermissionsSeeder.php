<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style permissions for Feedback (view-focused Filament resource).
 */
class FeedbackPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $names = [];
        foreach (['ViewAny', 'View', 'Delete', 'DeleteAny'] as $ability) {
            $names[] = Permission::findOrCreate("{$ability}:Feedback", 'web')->name;
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->givePermissionTo($names);
    }
}
