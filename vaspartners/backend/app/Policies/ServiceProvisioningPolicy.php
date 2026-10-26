<?php

namespace App\Policies;

use App\Models\ServiceProvisioning;
use App\Models\User;

class ServiceProvisioningPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ServiceProvisioning');
    }

    public function view(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('View:ServiceProvisioning') || $user->can('ViewAny:ServiceProvisioning');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('Update:ServiceProvisioning');
    }

    public function delete(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('Delete:ServiceProvisioning');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:ServiceProvisioning');
    }

    public function restore(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('Restore:ServiceProvisioning');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:ServiceProvisioning');
    }

    public function forceDelete(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ForceDelete:ServiceProvisioning');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:ServiceProvisioning');
    }

    /** Submit sales → operation configuration pack. */
    public function actSalesPack(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActSalesPack:ServiceProvisioning');
    }

    /** Operation fan-out / handback (MA, Billing, Sales). */
    public function actServiceConfig(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActServiceConfig:ServiceProvisioning');
    }

    /** IS Billing configuration completed. */
    public function actBillingConfig(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActBillingConfig:ServiceProvisioning');
    }

    /** MA product / credentials creation. */
    public function actProductCreation(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActProductCreation:ServiceProvisioning');
    }

    /** Share credentials with partner. */
    public function actShareCredentials(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActShareCredentials:ServiceProvisioning');
    }

    /** Security assessment request / clearance. */
    public function actSecurityAssessment(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActSecurityAssessment:ServiceProvisioning');
    }

    /** Go Live approval. */
    public function actGoLive(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ActGoLive:ServiceProvisioning');
    }

    /** View released credentials on the case. */
    public function viewCredentials(User $user, ServiceProvisioning $case): bool
    {
        return $user->can('ViewCredentials:ServiceProvisioning');
    }
}
