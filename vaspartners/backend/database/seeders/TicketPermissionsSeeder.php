<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shield-style Ticket SMS permissions.
 * Other roles: assign SendSms / SendSmsAny via Roles UI.
 * Super admin: always granted those SMS abilities here.
 */
class TicketPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'SendSms',
            'SendSmsAny',
        ] as $ability) {
            Permission::findOrCreate("{$ability}:Ticket", 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->givePermissionTo([
            'SendSms:Ticket',
            'SendSmsAny:Ticket',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
