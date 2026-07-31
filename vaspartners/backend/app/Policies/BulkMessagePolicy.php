<?php

namespace App\Policies;

use App\Enums\BulkMessageStatus;
use App\Models\BulkMessage;
use App\Models\User;

class BulkMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:BulkMessage');
    }

    public function view(User $user, BulkMessage $bulkMessage): bool
    {
        return $user->can('View:BulkMessage');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:BulkMessage');
    }

    public function update(User $user, BulkMessage $bulkMessage): bool
    {
        return $user->can('Update:BulkMessage');
    }

    public function delete(User $user, BulkMessage $bulkMessage): bool
    {
        if (! $user->can('Delete:BulkMessage')) {
            return false;
        }

        // Only the campaign owner may delete (including drafts with no SMS sent).
        if ((int) $bulkMessage->created_by_user_id !== (int) $user->id) {
            return false;
        }

        return ! in_array($bulkMessage->status, [
            BulkMessageStatus::Importing,
            BulkMessageStatus::Queued,
            BulkMessageStatus::Processing,
        ], true);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:BulkMessage');
    }

    public function restore(User $user, BulkMessage $bulkMessage): bool
    {
        return $user->can('Restore:BulkMessage');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:BulkMessage');
    }

    public function forceDelete(User $user, BulkMessage $bulkMessage): bool
    {
        return $user->can('ForceDelete:BulkMessage');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:BulkMessage');
    }

    public function replicate(User $user, BulkMessage $bulkMessage): bool
    {
        return $user->can('Replicate:BulkMessage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:BulkMessage');
    }
}
