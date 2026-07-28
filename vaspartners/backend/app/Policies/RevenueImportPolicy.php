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

        $families = $user->managedRevenueFamilyValues();
        if ($families === []) {
            return false;
        }

        return $revenueImport->rows()
            ->whereIn('service_family', $families)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAllRevenue() && $user->can('Create:RevenueImport');
    }

    public function update(User $user, RevenueImport $revenueImport): bool
    {
        return $user->canAccessAllRevenue() && $user->can('Update:RevenueImport');
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
