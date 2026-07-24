<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Account Manager: My Tickets only (view/update own assigned tickets).
 * No All Tickets list, no catalog/partner/user admin.
 */
class AccountManagerRoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            Permission::findOrCreate('View:MyTickets', 'web'),
            Permission::findOrCreate('View:Ticket', 'web'),
            Permission::findOrCreate('Update:Ticket', 'web'),
        ];

        $role = Role::findOrCreate('account_manager', 'web');
        $role->syncPermissions($permissions);

        // Keep My Tickets available to roles that already manage all tickets
        $myTickets = $permissions[0];
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        if (! $superAdmin->hasPermissionTo($myTickets)) {
            $superAdmin->givePermissionTo($myTickets);
        }
        foreach (Role::query()->where('guard_name', 'web')->get() as $existing) {
            if ($existing->name === 'account_manager') {
                continue;
            }
            $managesAllTickets = Permission::query()
                ->where('name', 'ViewAny:Ticket')
                ->where('guard_name', 'web')
                ->exists()
                && $existing->hasPermissionTo('ViewAny:Ticket');
            if ($managesAllTickets && ! $existing->hasPermissionTo($myTickets)) {
                $existing->givePermissionTo($myTickets);
            }
        }

        $this->command?->info('Role account_manager synced (View:MyTickets, View:Ticket, Update:Ticket).');
    }
}
