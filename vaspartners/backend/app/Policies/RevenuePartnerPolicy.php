<?php

namespace App\Policies;

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
        if (! $user->can('View:RevenuePartner')) {
            return false;
        }

        return $this->withinScope($user, $revenuePartner);
    }

    public function create(User $user): bool
    {
        return $user->canAccessAllRevenue() && $user->can('Create:RevenuePartner');
    }

    public function update(User $user, RevenuePartner $revenuePartner): bool
    {
        if (! $user->can('Update:RevenuePartner')) {
            return false;
        }

        return $this->withinScope($user, $revenuePartner);
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

    protected function withinScope(User $user, RevenuePartner $partner): bool
    {
        if ($user->canAccessAllRevenue()) {
            return true;
        }

        $family = $partner->vas_service_id;

        return $user->managesRevenueService($family ? (int) $family : null);
    }
}
