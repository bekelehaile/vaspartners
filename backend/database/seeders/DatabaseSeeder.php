<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@vaspartners.local'],
            [
                'name' => 'VAS Admin',
                'password' => Hash::make('password'),
                'is_management' => true,
                'is_active' => true,
            ]
        );

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        if (! $admin->hasRole($superAdmin)) {
            $admin->assignRole($superAdmin);
        }

        // My Tickets page (Filament Shield) — assign to anyone who can view tickets
        $myTickets = Permission::findOrCreate('View:MyTickets', 'web');
        $superAdmin->givePermissionTo($myTickets);
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->hasPermissionTo('ViewAny:Ticket')) {
                $role->givePermissionTo($myTickets);
            }
        }

        foreach ([
            ['name' => 'Low', 'code' => 'low', 'weight' => 1, 'color' => 'gray'],
            ['name' => 'Medium', 'code' => 'medium', 'weight' => 2, 'color' => 'blue'],
            ['name' => 'High', 'code' => 'high', 'weight' => 3, 'color' => 'orange'],
            ['name' => 'Critical', 'code' => 'critical', 'weight' => 4, 'color' => 'red'],
        ] as $row) {
            Priority::query()->updateOrCreate(['code' => $row['code']], $row);
        }

        $this->call(CatalogSeeder::class);
        $this->call(OptionalDocumentIfAnySeeder::class);
    }
}
