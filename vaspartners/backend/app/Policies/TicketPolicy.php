<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Ticket');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if (! $user->can('View:Ticket')) {
            return false;
        }

        if ($user->can('ViewAny:Ticket')) {
            return true;
        }

        return $this->isAssignedOrApprover($user, $ticket);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Ticket');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if (! $user->can('Update:Ticket')) {
            return false;
        }

        if ($user->can('ViewAny:Ticket')) {
            return true;
        }

        return $this->isAssignedOrApprover($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->can('Delete:Ticket');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Ticket');
    }

    public function restore(User $user, Ticket $ticket): bool
    {
        return $user->can('Restore:Ticket');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Ticket');
    }

    public function forceDelete(User $user, Ticket $ticket): bool
    {
        return $user->can('ForceDelete:Ticket');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:Ticket');
    }

    public function replicate(User $user, Ticket $ticket): bool
    {
        return $user->can('Replicate:Ticket');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Ticket');
    }

    protected function isAssignedOrApprover(User $user, Ticket $ticket): bool
    {
        return (int) $ticket->assigned_to_user_id === (int) $user->id
            || (int) $ticket->current_approver_user_id === (int) $user->id;
    }
}
