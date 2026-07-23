<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Staff accounts from mvas_20260723_160413.dump (users table).
 * Passwords are reset to "password" for local/dev — dump hashes are not imported.
 */
class MvasStaffUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        // legacy_id => row (manager_legacy_id remapped after insert)
        $rows = [
            ['legacy_id' => 1, 'manager_legacy_id' => null, 'name' => 'bekele haile nassa', 'phone' => '930011756', 'email' => 'bekele.haile@ethiotelecom.et', 'is_management' => true, 'superuser' => true],
            ['legacy_id' => 2, 'manager_legacy_id' => 5, 'name' => 'abayneh mekonnen asfaw', 'phone' => '924783562', 'email' => 'abayneh.mekonnen@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 3, 'manager_legacy_id' => null, 'name' => 'million mekibib mulugeta', 'phone' => '911210391', 'email' => 'million.mekibib@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 4, 'manager_legacy_id' => 5, 'name' => 'samuel tsehay gebremeskel', 'phone' => '911502703', 'email' => 'samuel.tsehay@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 5, 'manager_legacy_id' => 17, 'name' => 'tamiru teka weldekidan', 'phone' => '930011546', 'email' => 'tamiru.tekaw@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 6, 'manager_legacy_id' => 5, 'name' => 'samrawit awoke kassaye', 'phone' => '911424822', 'email' => 'samrawit.awoke@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 7, 'manager_legacy_id' => 5, 'name' => 'kalkidan sahle habtemariam', 'phone' => '910170805', 'email' => 'kalkidan.sahle@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 8, 'manager_legacy_id' => 25, 'name' => 'aziza ali kemal', 'phone' => '930073198', 'email' => 'aziza.ali@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 9, 'manager_legacy_id' => 25, 'name' => 'tolasa deressa gudata', 'phone' => '911528637', 'email' => 'tolasa.deressa@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 10, 'manager_legacy_id' => 17, 'name' => 'amsalu tadesse tefera', 'phone' => '911210452', 'email' => 'amsalu.tadesse@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 11, 'manager_legacy_id' => 25, 'name' => 'misrak abubeker dawud', 'phone' => '911500284', 'email' => 'misrak.abubeker@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 12, 'manager_legacy_id' => 25, 'name' => 'meskerem tamene yirdaw', 'phone' => '911508600', 'email' => 'meskerem.tamene@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 13, 'manager_legacy_id' => 25, 'name' => 'mohamed said muhea', 'phone' => '911506701', 'email' => 'mohamed.saidm@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 14, 'manager_legacy_id' => 10, 'name' => 'amsalu molla misker', 'phone' => '930333231', 'email' => 'amsalu.mollam@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 15, 'manager_legacy_id' => null, 'name' => 'samuel dadi leta', 'phone' => '911222222', 'email' => 'samuel.dadi@ethiotelcom.et', 'is_management' => false],
            ['legacy_id' => 17, 'manager_legacy_id' => 3, 'name' => 'biruk fekade gebremeskel', 'phone' => '911509896', 'email' => 'biruk.fekade@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 23, 'manager_legacy_id' => 5, 'name' => 'meron melkamu aynalem', 'phone' => '930800290', 'email' => 'meron.melkamu@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 24, 'manager_legacy_id' => null, 'name' => 'mohammed haji abdulahi', 'phone' => '911206595', 'email' => 'mohammed.haji@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 25, 'manager_legacy_id' => 17, 'name' => 'dereje negera g/michael', 'phone' => '911506819', 'email' => 'dereje.negera@ethiotelecom.et', 'is_management' => true],
            ['legacy_id' => 26, 'manager_legacy_id' => 25, 'name' => 'kidist abate abebe', 'phone' => '943181179', 'email' => 'kidist.abate@ethiotelecom.et', 'is_management' => false],
            ['legacy_id' => 28, 'manager_legacy_id' => 25, 'name' => 'selome tilahun belete', 'phone' => '942404829', 'email' => 'selome.tilahun@ethiotelecom.et', 'is_management' => false],
        ];

        $byLegacy = [];

        foreach ($rows as $row) {
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'username' => $row['phone'],
                    'phone' => $row['phone'],
                    'password' => $password,
                    'is_management' => $row['is_management'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'manager_id' => null,
                ]
            );

            $byLegacy[$row['legacy_id']] = $user;

            if (! empty($row['superuser'])) {
                $superAdmin = Role::findOrCreate('super_admin', 'web');
                if (! $user->hasRole($superAdmin)) {
                    $user->assignRole($superAdmin);
                }
            } else {
                // Default staff role
                $accountManager = Role::findOrCreate('account_manager', 'web');
                if (! $user->hasRole($accountManager)) {
                    $user->assignRole($accountManager);
                }
            }
        }

        foreach ($rows as $row) {
            $managerLegacy = $row['manager_legacy_id'];
            if ($managerLegacy === null || ! isset($byLegacy[$managerLegacy], $byLegacy[$row['legacy_id']])) {
                continue;
            }

            $user = $byLegacy[$row['legacy_id']];
            $managerId = $byLegacy[$managerLegacy]->id;
            if ($user->manager_id !== $managerId) {
                $user->forceFill(['manager_id' => $managerId])->save();
            }
        }

        $this->command?->info('Seeded '.count($rows).' MVAS staff users (password: password).');
    }
}
