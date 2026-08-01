<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Contact');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('View:Contact');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Contact $contact): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole('super_admin');
    }

    public function delete(User $user, Contact $contact): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Contact $contact): bool
    {
        return false;
    }

    public function forceDelete(User $user, Contact $contact): bool
    {
        return false;
    }
}
