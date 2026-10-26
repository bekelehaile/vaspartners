<?php

namespace App\Services\Provisioning;

/**
 * Shield / Spatie permissions for service-activation step actions.
 * Granted on Roles (Shield UI) under ServiceProvisioning.
 */
class ProvisioningPermissions
{
    /**
     * Policy ability name for a workflow step key (staff actions only).
     */
    public static function abilityForStep(string $stepKey): ?string
    {
        return match ($stepKey) {
            'sales_to_operation' => 'actSalesPack',
            'operation_to_ma',
            'operation_to_billing',
            'operation_to_sales' => 'actServiceConfig',
            'billing_to_operation' => 'actBillingConfig',
            'ma_to_operation' => 'actProductCreation',
            'sales_share_credentials' => 'actShareCredentials',
            'sales_to_security',
            'security_clearance' => 'actSecurityAssessment',
            'go_live' => 'actGoLive',
            default => null,
        };
    }

    /**
     * Spatie permission name shown / assigned in Shield.
     */
    public static function permissionForStep(string $stepKey): ?string
    {
        $ability = static::abilityForStep($stepKey);

        return $ability ? static::permissionForAbility($ability) : null;
    }

    public static function permissionForAbility(string $ability): string
    {
        return match ($ability) {
            'actSalesPack' => 'ActSalesPack:ServiceProvisioning',
            'actServiceConfig' => 'ActServiceConfig:ServiceProvisioning',
            'actBillingConfig' => 'ActBillingConfig:ServiceProvisioning',
            'actProductCreation' => 'ActProductCreation:ServiceProvisioning',
            'actShareCredentials' => 'ActShareCredentials:ServiceProvisioning',
            'actSecurityAssessment' => 'ActSecurityAssessment:ServiceProvisioning',
            'actGoLive' => 'ActGoLive:ServiceProvisioning',
            'viewCredentials' => 'ViewCredentials:ServiceProvisioning',
            default => $ability.':ServiceProvisioning',
        };
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [
            'ViewAny:ServiceProvisioning',
            'View:ServiceProvisioning',
            'Update:ServiceProvisioning',
            'Delete:ServiceProvisioning',
            'DeleteAny:ServiceProvisioning',
            'ActSalesPack:ServiceProvisioning',
            'ActServiceConfig:ServiceProvisioning',
            'ActBillingConfig:ServiceProvisioning',
            'ActProductCreation:ServiceProvisioning',
            'ActShareCredentials:ServiceProvisioning',
            'ActSecurityAssessment:ServiceProvisioning',
            'ActGoLive:ServiceProvisioning',
            'ViewCredentials:ServiceProvisioning',
        ];
    }
}
