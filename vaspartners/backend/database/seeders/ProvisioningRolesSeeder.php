<?php

namespace Database\Seeders;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningWorkflow;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ProvisioningRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'View:ServiceProvisioning',
            'ViewAny:ServiceProvisioning',
            'Update:ServiceProvisioning',
            'Act:ProvisioningResourceAllocation',
            'Act:ProvisioningSalesPack',
            'Act:ProvisioningServiceConfig',
            'Act:ProvisioningBillingConfig',
            'Act:ProvisioningProductCreation',
            'Act:ProvisioningShareCredentials',
            'View:ProvisioningCredentials',
            'Act:ProvisioningSecurityAssessment',
            'Act:ProvisioningGoLive',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $rolePerms = [
            ProvisioningDepartment::VasSales->value => [
                'View:ServiceProvisioning', 'ViewAny:ServiceProvisioning', 'Update:ServiceProvisioning',
                'Act:ProvisioningSalesPack', 'Act:ProvisioningShareCredentials',
                'Act:ProvisioningSecurityAssessment', 'Act:ProvisioningGoLive',
            ],
            ProvisioningDepartment::VasOperation->value => [
                'View:ServiceProvisioning', 'ViewAny:ServiceProvisioning', 'Update:ServiceProvisioning',
                'Act:ProvisioningServiceConfig', 'View:ProvisioningCredentials',
            ],
            ProvisioningDepartment::IsBilling->value => [
                'View:ServiceProvisioning', 'ViewAny:ServiceProvisioning', 'Update:ServiceProvisioning',
                'Act:ProvisioningBillingConfig',
            ],
            ProvisioningDepartment::Ma->value => [
                'View:ServiceProvisioning', 'ViewAny:ServiceProvisioning', 'Update:ServiceProvisioning',
                'Act:ProvisioningProductCreation', 'View:ProvisioningCredentials',
            ],
            ProvisioningDepartment::IsSecurity->value => [
                'View:ServiceProvisioning', 'ViewAny:ServiceProvisioning', 'Update:ServiceProvisioning',
                'Act:ProvisioningSecurityAssessment',
            ],
        ];

        foreach ($rolePerms as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->givePermissionTo($perms);
        }

        // Account managers open / submit sales-side activation on cases they already own.
        $accountManager = Role::findOrCreate('account_manager', 'web');
        $accountManager->givePermissionTo([
            'View:ServiceProvisioning',
            'ViewAny:ServiceProvisioning',
            'Update:ServiceProvisioning',
            'Act:ProvisioningSalesPack',
            'Act:ProvisioningShareCredentials',
            'Act:ProvisioningSecurityAssessment',
            'Act:ProvisioningGoLive',
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
