<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('username', 'admin')
            ->orWhere('email', 'admin@demo.com')
            ->orWhere('email', 'admin')
            ->first();

        if ($admin) {
            $admin->fill([
                'name' => 'VAS Admin',
                'username' => 'admin',
                'email' => 'admin@demo.com',
                'phone' => '911000000',
                'password' => Hash::make('password'),
                'is_management' => true,
                'is_active' => true,
            ])->save();
        } else {
            $admin = User::query()->create([
                'name' => 'VAS Admin',
                'username' => 'admin',
                'email' => 'admin@demo.com',
                'phone' => '911000000',
                'password' => Hash::make('password'),
                'is_management' => true,
                'is_active' => true,
            ]);
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        if (! $admin->hasRole($superAdmin)) {
            $admin->assignRole($superAdmin);
        }

        foreach ([
            ['name' => 'Low', 'code' => 'low', 'weight' => 1, 'color' => 'gray'],
            ['name' => 'Medium', 'code' => 'medium', 'weight' => 2, 'color' => 'blue'],
            ['name' => 'High', 'code' => 'high', 'weight' => 3, 'color' => 'orange'],
            ['name' => 'Critical', 'code' => 'critical', 'weight' => 4, 'color' => 'red'],
        ] as $row) {
            Priority::query()->updateOrCreate(['code' => $row['code']], $row);
        }

        $this->call(AccountManagerRoleSeeder::class);
        $this->call(CatalogSeeder::class);
        $this->call(OptionalDocumentIfAnySeeder::class);
        $this->call(MvasStaffUsersSeeder::class);
    }
}
