<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style permissions for BulkMessage (Filament resource).
 * Assign via Roles UI; super_admin bypasses via Gate::before.
 */
class BulkMessagePermissionsSeeder extends Seeder
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

        foreach ($abilities as $ability) {
            Permission::findOrCreate("{$ability}:BulkMessage", 'web');
        }
    }
}
