<?php

namespace App\Policies;

use App\Models\AppSetting;
use App\Models\RevenuePartner;
use App\Models\User;

class RevenuePartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:RevenuePartner');
    }

    public function view(User $user, RevenuePartner $revenuePartner): bool
    {
        if ($user->canAccessAllRevenue()) {
            return true;
        }

        if (! $user->can('View:RevenuePartner')) {
            return false;
        }

        return $this->ownsOrAdmin($user, $revenuePartner);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:RevenuePartner') || $user->canAccessAllRevenue();
    }

    public function update(User $user, RevenuePartner $revenuePartner): bool
    {
        if ($user->canAccessAllRevenue()) {
            return true;
        }

        if (! $user->can('Update:RevenuePartner')) {
            return false;
        }

        return $this->ownsOrAdmin($user, $revenuePartner);
    }

    public function delete(User $user, RevenuePartner $revenuePartner): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RevenuePartner $revenuePartner): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, RevenuePartner $revenuePartner): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, RevenuePartner $revenuePartner): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    protected function ownsOrAdmin(User $user, RevenuePartner $revenuePartner): bool
    {
        return AppSetting::canActForRevenueOwner(
            $user,
            $revenuePartner->created_by_user_id ? (int) $revenuePartner->created_by_user_id : null,
        );
    }
}
