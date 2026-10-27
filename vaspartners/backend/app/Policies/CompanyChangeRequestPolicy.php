<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CompanyChangeRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class CompanyChangeRequestPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CompanyChangeRequest');
    }

    public function view(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('View:CompanyChangeRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CompanyChangeRequest');
    }

    public function update(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('Update:CompanyChangeRequest');
    }

    public function delete(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('Delete:CompanyChangeRequest');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CompanyChangeRequest');
    }

    public function restore(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('Restore:CompanyChangeRequest');
    }

    public function forceDelete(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('ForceDelete:CompanyChangeRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CompanyChangeRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CompanyChangeRequest');
    }

    public function replicate(AuthUser $authUser, CompanyChangeRequest $companyChangeRequest): bool
    {
        return $authUser->can('Replicate:CompanyChangeRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CompanyChangeRequest');
    }

}