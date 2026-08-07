<?php

namespace App\Policies;

use App\Enums\RevenueImportStatus;
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

        // AMs only open imports they created.
        return (int) $revenueImport->created_by_user_id === (int) $user->id;
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

        $status = $revenueImport->status instanceof RevenueImportStatus
            ? $revenueImport->status
            : RevenueImportStatus::tryFrom((string) $revenueImport->status);

        if (in_array($status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)
            || filled($revenueImport->bulk_message_id)) {
            return false;
        }

        if ($user->canAccessAllRevenue()) {
            return true;
        }

        return (int) $revenueImport->created_by_user_id === (int) $user->id;
    }

    public function delete(User $user, RevenueImport $revenueImport): bool
    {
        if (! $user->can('Delete:RevenueImport')) {
            return false;
        }

        if (! $revenueImport->canBeDeleted()) {
            return false;
        }

        // Super admin / management: any deletable import.
        if ($user->canAccessAllRevenue()) {
            return true;
        }

        // Account managers: only imports they created.
        return (int) $revenueImport->created_by_user_id === (int) $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:RevenueImport') || $user->can('Delete:RevenueImport');
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
