<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style Ticket SMS + reject permissions.
 * Other roles: assign via Roles UI.
 * Super admin: always granted here.
 */
class TicketPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'SendSms',
            'SendSmsAny',
            'Reject',
        ] as $ability) {
            Permission::findOrCreate("{$ability}:Ticket", 'web');
        }

        // Rename legacy permission if present.
        $legacy = Permission::query()
            ->where('name', 'RejectAsDispatcher:Ticket')
            ->where('guard_name', 'web')
            ->first();
        if ($legacy) {
            $reject = Permission::findOrCreate('Reject:Ticket', 'web');
            foreach ($legacy->roles as $role) {
                $role->givePermissionTo($reject);
                $role->revokePermissionTo($legacy);
            }
            $legacy->delete();
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->givePermissionTo([
            'SendSms:Ticket',
            'SendSmsAny:Ticket',
            'Reject:Ticket',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
