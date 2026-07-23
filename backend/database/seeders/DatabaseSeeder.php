<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@vaspartners.local'],
            [
                'name' => 'VAS Admin',
                'password' => Hash::make('password'),
                'is_management' => true,
                'is_active' => true,
            ]
        );

        foreach ([
            ['name' => 'Low', 'code' => 'low', 'weight' => 1, 'color' => 'gray'],
            ['name' => 'Medium', 'code' => 'medium', 'weight' => 2, 'color' => 'blue'],
            ['name' => 'High', 'code' => 'high', 'weight' => 3, 'color' => 'orange'],
            ['name' => 'Critical', 'code' => 'critical', 'weight' => 4, 'color' => 'red'],
        ] as $row) {
            Priority::query()->updateOrCreate(['code' => $row['code']], $row);
        }

        $this->call(CatalogSeeder::class);
    }
}
