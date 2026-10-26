<?php

namespace Database\Seeders;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningWorkflow;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ProvisioningRolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ProvisioningPermissions::allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Legacy names from earlier seed — keep so existing role pivots do not break until re-synced.
        foreach ([
            'Act:ProvisioningResourceAllocation',
            'Act:ProvisioningSalesPack',
            'Act:ProvisioningServiceConfig',
            'Act:ProvisioningBillingConfig',
            'Act:ProvisioningProductCreation',
            'Act:ProvisioningShareCredentials',
            'View:ProvisioningCredentials',
            'Act:ProvisioningSecurityAssessment',
            'Act:ProvisioningGoLive',
        ] as $legacy) {
            Permission::findOrCreate($legacy, 'web');
        }

        $rolePerms = [
            ProvisioningDepartment::VasSales->value => [
                'View:ServiceProvisioning',
                'ViewAny:ServiceProvisioning',
                'Update:ServiceProvisioning',
                'ActSalesPack:ServiceProvisioning',
                'ActShareCredentials:ServiceProvisioning',
                'ActSecurityAssessment:ServiceProvisioning',
                'ActGoLive:ServiceProvisioning',
                'ViewCredentials:ServiceProvisioning',
            ],
            ProvisioningDepartment::VasOperation->value => [
                'View:ServiceProvisioning',
                'ViewAny:ServiceProvisioning',
                'Update:ServiceProvisioning',
                'ActServiceConfig:ServiceProvisioning',
                'ViewCredentials:ServiceProvisioning',
            ],
            ProvisioningDepartment::IsBilling->value => [
                'View:ServiceProvisioning',
                'ViewAny:ServiceProvisioning',
                'Update:ServiceProvisioning',
                'ActBillingConfig:ServiceProvisioning',
            ],
            ProvisioningDepartment::Ma->value => [
                'View:ServiceProvisioning',
                'ViewAny:ServiceProvisioning',
                'Update:ServiceProvisioning',
                'ActProductCreation:ServiceProvisioning',
                'ViewCredentials:ServiceProvisioning',
            ],
            ProvisioningDepartment::IsSecurity->value => [
                'View:ServiceProvisioning',
                'ViewAny:ServiceProvisioning',
                'Update:ServiceProvisioning',
                'ActSecurityAssessment:ServiceProvisioning',
            ],
        ];

        foreach ($rolePerms as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->givePermissionTo($perms);
        }

        // Account managers: sales-side actions on assigned cases (Shield-grantable).
        $accountManager = Role::findOrCreate('account_manager', 'web');
        $accountManager->givePermissionTo([
            'View:ServiceProvisioning',
            'ViewAny:ServiceProvisioning',
            'Update:ServiceProvisioning',
            'ActSalesPack:ServiceProvisioning',
            'ActShareCredentials:ServiceProvisioning',
            'ActSecurityAssessment:ServiceProvisioning',
            'ActGoLive:ServiceProvisioning',
            'ViewCredentials:ServiceProvisioning',
        ]);

        Service::query()
            ->where(function ($q): void {
                $q->whereNull('provisioning_workflow')
                    ->orWhere('provisioning_workflow', '');
            })
            ->where(function ($q): void {
                $q->where('type', 'ilike', '%premium%')
                    ->orWhere('name', 'ilike', '%premium%')
                    ->orWhere('name', 'ilike', '%api%')
                    ->orWhere('name', 'ilike', '%ussd%')
                    ->orWhere('name', 'ilike', '%mt%');
            })
            ->update(['provisioning_workflow' => ProvisioningWorkflow::PremiumFull->value]);
    }
}
