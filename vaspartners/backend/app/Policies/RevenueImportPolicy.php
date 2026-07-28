<?php

namespace App\Policies;

use App\Models\RevenueImport;
use App\Models\User;

class RevenueImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:RevenueImport');
    }

    public function view(User $user, RevenueImport $revenueImport): bool
    {
        if (! $user->can('View:RevenueImport')) {
            return false;
        }

        if ($user->canAccessAllRevenue()) {
            return true;
        }

        $serviceIds = $user->managedRevenueServiceIds();
        if ($serviceIds === []) {
            return false;
        }

        return in_array((int) $revenueImport->vas_service_id, $serviceIds, true)
            && (
                (int) $revenueImport->created_by_user_id === (int) $user->id
                || $revenueImport->rows()->whereIn('vas_service_id', $serviceIds)->exists()
            );
    }

    public function create(User $user): bool
    {
        return $user->can('Create:RevenueImport');
    }

    public function update(User $user, RevenueImport $revenueImport): bool
    {
        if (! $user->can('Update:RevenueImport')) {
            return false;
        }

        if ($user->canAccessAllRevenue()) {
            return true;
        }

        if ((int) $revenueImport->created_by_user_id !== (int) $user->id) {
            return false;
        }

        return $user->managesRevenueService((int) $revenueImport->vas_service_id);
    }

    public function delete(User $user, RevenueImport $revenueImport): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RevenueImport $revenueImport): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, RevenueImport $revenueImport): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, RevenueImport $revenueImport): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
