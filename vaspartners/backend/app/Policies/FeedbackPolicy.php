<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

/** Admin can view partner quarterly feedback; partners submit via portal API. */
class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Feedback');
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $user->can('View:Feedback');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Feedback $feedback): bool
    {
        return false;
    }

    public function delete(User $user, Feedback $feedback): bool
    {
        return $user->can('Delete:Feedback');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Feedback');
    }

    public function restore(User $user, Feedback $feedback): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Feedback $feedback): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
