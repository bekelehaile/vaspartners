<?php

namespace App\Policies;

use App\Models\RevenueImportRow;
use App\Models\User;

class RevenueImportRowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:RevenueImport');
    }

    public function view(User $user, RevenueImportRow $revenueImportRow): bool
    {
        $import = $revenueImportRow->import;

        return $import
            ? $user->can('view', $import)
            : $user->can('ViewAny:RevenueImport');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RevenueImportRow $revenueImportRow): bool
    {
        return false;
    }

    public function delete(User $user, RevenueImportRow $revenueImportRow): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RevenueImportRow $revenueImportRow): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, RevenueImportRow $revenueImportRow): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, RevenueImportRow $revenueImportRow): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
