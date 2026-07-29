<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketApprovalStep;
use App\Models\TicketAssignment;
use App\Models\TicketComment;
use App\Models\TicketDocument;
use App\Models\TicketDocumentReview;
use App\Models\TicketStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Permanently remove a partner portal ticket that is still unhandled (open)
 * or was sent back (rejected), plus all related data.
 */
class TicketPurgeService
{
    public function __construct(
        protected CompanyMembershipService $membership,
    ) {}

    public function partnerMayDelete(Ticket $ticket): bool
    {
        return in_array($ticket->status, [TicketStatus::Open, TicketStatus::Rejected], true);
    }

    /**
     * Permanently remove a ticket and related rows (admin / ops use).
     *
     * @return array{tt_number: string, documents: int, comments: int, files_removed: int}
     */
    public function forcePurge(Ticket $ticket): array
    {
        $ttNumber = (string) $ticket->tt_number;
        $stats = [
            'tt_number' => $ttNumber,
            'documents' => 0,
            'comments' => 0,
            'files_removed' => 0,
        ];

        DB::transaction(function () use ($ticket, &$stats): void {
            $ticketId = (int) $ticket->getKey();

            $documents = TicketDocument::withTrashed()
                ->where('ticket_id', $ticketId)
                ->get(['id', 'disk', 'path']);
            $stats['documents'] = $documents->count();
            $stats['files_removed'] += $this->deleteDocumentFiles($documents->all());
            TicketDocument::withTrashed()->where('ticket_id', $ticketId)->forceDelete();

            $comments = TicketComment::withTrashed()
                ->where('ticket_id', $ticketId)
                ->get(['id', 'attachment_disk', 'attachment_path']);
            $stats['comments'] = $comments->count();
            $stats['files_removed'] += $this->deleteCommentAttachments($comments->all());
            TicketComment::withTrashed()->where('ticket_id', $ticketId)->forceDelete();

            TicketDocumentReview::query()->where('ticket_id', $ticketId)->delete();
            TicketApprovalStep::query()->where('ticket_id', $ticketId)->delete();
            TicketAssignment::query()->where('ticket_id', $ticketId)->delete();
            TicketStatusHistory::query()->where('ticket_id', $ticketId)->delete();

            // Detach children / parents so FK force-delete does not fail.
            Ticket::withTrashed()
                ->where('parent_ticket_id', $ticketId)
                ->update(['parent_ticket_id' => null]);

            $fresh = Ticket::withTrashed()->find($ticketId);
            if ($fresh) {
                $fresh->forceFill([
                    'subscription_id' => null,
                    'parent_ticket_id' => null,
                ])->save();
                $fresh->forceDelete();
            }
        });

        return $stats;
    }

    /**
     * @return array{tt_number: string, documents: int, comments: int, files_removed: int}
     */
    public function forcePurgeForContact(Ticket $ticket, Contact $actor): array
    {
        $this->membership->assertCanAccessCompanyTicket($actor, $ticket);

        if (! $this->partnerMayDelete($ticket)) {
            throw ValidationException::withMessages([
                'ticket' => 'Only open (not yet handled) or rejected service requests can be deleted from the portal.',
            ]);
        }

        return $this->forcePurge($ticket);
    }

    /** @deprecated Use forcePurgeForContact() */
    public function forcePurgeRejectedForContact(Ticket $ticket, Contact $actor): array
    {
        return $this->forcePurgeForContact($ticket, $actor);
    }

    /**
     * @param  list<TicketDocument>  $documents
     */
    protected function deleteDocumentFiles(array $documents): int
    {
        $removed = 0;
        foreach ($documents as $doc) {
            if (! filled($doc->path)) {
                continue;
            }
            try {
                $disk = $doc->disk ?: 'public';
                if (Storage::disk($disk)->exists($doc->path)) {
                    Storage::disk($disk)->delete($doc->path);
                    $removed++;
                }
            } catch (Throwable $e) {
                Log::warning('Ticket purge: could not delete document file', [
                    'path' => $doc->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * @param  list<TicketComment>  $comments
     */
    protected function deleteCommentAttachments(array $comments): int
    {
        $removed = 0;
        foreach ($comments as $comment) {
            if (! filled($comment->attachment_path)) {
                continue;
            }
            try {
                $disk = $comment->attachment_disk ?: 'local';
                if (Storage::disk($disk)->exists($comment->attachment_path)) {
                    Storage::disk($disk)->delete($comment->attachment_path);
                    $removed++;
                }
            } catch (Throwable $e) {
                Log::warning('Ticket purge: could not delete comment attachment', [
                    'path' => $comment->attachment_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }
}
