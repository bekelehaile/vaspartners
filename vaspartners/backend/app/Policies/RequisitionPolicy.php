<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Requisition;
use Illuminate\Auth\Access\HandlesAuthorization;

class RequisitionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Requisition');
    }

    public function view(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('View:Requisition');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Requisition');
    }

    public function update(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Update:Requisition');
    }

    public function delete(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Delete:Requisition');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Requisition');
    }

    public function restore(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Restore:Requisition');
    }

    public function forceDelete(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('ForceDelete:Requisition');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Requisition');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Requisition');
    }

    public function replicate(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Replicate:Requisition');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Requisition');
    }

}