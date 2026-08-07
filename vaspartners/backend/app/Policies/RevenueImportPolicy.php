<?php

namespace App\Policies;

use App\Enums\RevenueImportStatus;
use App\Models\AppSetting;
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
        if ($user->canAccessAllRevenue()) {
            return true;
        }

        if (! $user->can('View:RevenueImport')) {
            return false;
        }

        return AppSetting::canActForRevenueOwner(
            $user,
            $revenueImport->created_by_user_id ? (int) $revenueImport->created_by_user_id : null,
        );
    }

    public function create(User $user): bool
    {
        return $user->can('Create:RevenueImport') || $user->canAccessAllRevenue();
    }

    public function update(User $user, RevenueImport $revenueImport): bool
    {
        if ($user->canAccessAllRevenue()) {
            $status = $revenueImport->status instanceof RevenueImportStatus
                ? $revenueImport->status
                : RevenueImportStatus::tryFrom((string) $revenueImport->status);

            return ! in_array($status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true);
        }

        if (! $user->can('Update:RevenueImport')) {
            return false;
        }

        $status = $revenueImport->status instanceof RevenueImportStatus
            ? $revenueImport->status
            : RevenueImportStatus::tryFrom((string) $revenueImport->status);

        if (in_array($status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            return false;
        }

        return AppSetting::canActForRevenueOwner(
            $user,
            $revenueImport->created_by_user_id ? (int) $revenueImport->created_by_user_id : null,
        );
    }

    public function delete(User $user, RevenueImport $revenueImport): bool
    {
        if (! $revenueImport->canBeDeleted()) {
            return false;
        }

        if ($user->canAccessAllRevenue()) {
            return true;
        }

        if (! $user->can('Delete:RevenueImport')) {
            return false;
        }

        return AppSetting::canActForRevenueOwner(
            $user,
            $revenueImport->created_by_user_id ? (int) $revenueImport->created_by_user_id : null,
        );
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
