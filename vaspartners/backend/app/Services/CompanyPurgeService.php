<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyChangeRequest;
use App\Models\CompanyMembership;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently remove a company and cascading partner data (memberships, tickets,
 * documents, subscriptions, and contacts that only belonged to this company).
 */
class CompanyPurgeService
{
    /**
     * @return array{
     *   company: string,
     *   memberships: int,
     *   subscriptions: int,
     *   tickets: int,
     *   documents: int,
     *   change_requests: int,
     *   contacts: int,
     *   files_removed: int
     * }
     */
    public function forcePurge(Company $company): array
    {
        $stats = [
            'company' => (string) ($company->name ?: $company->public_id),
            'memberships' => 0,
            'subscriptions' => 0,
            'tickets' => 0,
            'documents' => 0,
            'change_requests' => 0,
            'contacts' => 0,
            'files_removed' => 0,
        ];

        return DB::transaction(function () use ($company, &$stats) {
            $companyId = (int) $company->getKey();
            $company->loadMissing(['memberships']);

            $memberContactIds = CompanyMembership::query()
                ->where('company_id', $companyId)
                ->pluck('contact_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($company->created_by_contact_id) {
                $memberContactIds = $memberContactIds
                    ->push((int) $company->created_by_contact_id)
                    ->unique()
                    ->values();
            }

            $ticketIds = $this->ticketIdsForCompany($company)->all();

            $documents = TicketDocument::withTrashed()
                ->whereIn('ticket_id', $ticketIds)
                ->get(['id', 'disk', 'path']);
            $stats['documents'] = $documents->count();
            $stats['files_removed'] += $this->deleteStoredFiles($documents->all());

            TicketDocument::withTrashed()->whereIn('ticket_id', $ticketIds)->forceDelete();

            $comments = TicketComment::withTrashed()
                ->whereIn('ticket_id', $ticketIds)
                ->whereNotNull('attachment_path')
                ->get(['id', 'attachment_disk', 'attachment_path']);
            $stats['files_removed'] += $this->deleteCommentAttachments($comments->all());

            TicketComment::withTrashed()->whereIn('ticket_id', $ticketIds)->forceDelete();

            Ticket::withTrashed()
                ->whereIn('id', $ticketIds)
                ->update([
                    'subscription_id' => null,
                    'parent_ticket_id' => null,
                ]);
            $stats['tickets'] = Ticket::withTrashed()->whereIn('id', $ticketIds)->count();
            Ticket::withTrashed()->whereIn('id', $ticketIds)->forceDelete();

            $subscriptions = Subscription::withTrashed()->where('company_id', $companyId)->get();
            $stats['subscriptions'] = $subscriptions->count();
            foreach ($subscriptions as $subscription) {
                $subscription->forceDelete();
            }

            $changeRequests = CompanyChangeRequest::withTrashed()
                ->where('company_id', $companyId)
                ->get();
            $stats['change_requests'] = $changeRequests->count();
            $stats['files_removed'] += $this->deleteChangeRequestFiles($changeRequests->all());
            CompanyChangeRequest::withTrashed()->where('company_id', $companyId)->forceDelete();

            $stats['memberships'] = CompanyMembership::query()->where('company_id', $companyId)->count();
            CompanyMembership::query()->where('company_id', $companyId)->delete();

            Contact::withTrashed()
                ->where('current_company_id', $companyId)
                ->update([
                    'current_company_id' => null,
                    'company_name' => null,
                    'company_tin' => null,
                    'company_phone' => null,
                    'company_email' => null,
                    'company_address' => null,
                ]);

            Company::query()->whereKey($companyId)->update(['created_by_contact_id' => null]);

            foreach ($memberContactIds as $contactId) {
                $contact = Contact::withTrashed()->find($contactId);
                if (! $contact) {
                    continue;
                }

                $stillLinked = CompanyMembership::query()
                    ->where('contact_id', $contactId)
                    ->exists();

                if ($stillLinked) {
                    continue;
                }

                // Wipe leftover personal tickets/docs for contacts that only belonged here.
                $leftoverTicketIds = Ticket::withTrashed()
                    ->where('contact_id', $contactId)
                    ->pluck('id')
                    ->all();
                if ($leftoverTicketIds !== []) {
                    $leftoverDocs = TicketDocument::withTrashed()
                        ->whereIn('ticket_id', $leftoverTicketIds)
                        ->get(['id', 'disk', 'path']);
                    $stats['files_removed'] += $this->deleteStoredFiles($leftoverDocs->all());
                    TicketDocument::withTrashed()->whereIn('ticket_id', $leftoverTicketIds)->forceDelete();
                    $leftoverComments = TicketComment::withTrashed()
                        ->whereIn('ticket_id', $leftoverTicketIds)
                        ->whereNotNull('attachment_path')
                        ->get(['id', 'attachment_disk', 'attachment_path']);
                    $stats['files_removed'] += $this->deleteCommentAttachments($leftoverComments->all());
                    TicketComment::withTrashed()->whereIn('ticket_id', $leftoverTicketIds)->forceDelete();
                    Ticket::withTrashed()->whereIn('id', $leftoverTicketIds)->forceDelete();
                }

                Subscription::withTrashed()->where('contact_id', $contactId)->forceDelete();
                CompanyChangeRequest::withTrashed()
                    ->where(function ($q) use ($contactId) {
                        $q->where('contact_id', $contactId)
                            ->orWhere('target_contact_id', $contactId);
                    })
                    ->forceDelete();

                $contact->tokens()->delete();
                $contact->notifications()->delete();
                $contact->forceDelete();
                $stats['contacts']++;
            }

            $company->refresh();
            if ($company->trashed()) {
                $company->forceDelete();
            } else {
                $company->forceDelete();
            }

            Log::info('Company force-purged', $stats);

            return $stats;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function ticketIdsForCompany(Company $company)
    {
        $companyId = (int) $company->getKey();

        $memberContactIds = CompanyMembership::query()
            ->where('company_id', $companyId)
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $exclusiveContactIds = $memberContactIds->filter(function (int $contactId) use ($companyId): bool {
            return ! CompanyMembership::query()
                ->where('contact_id', $contactId)
                ->where('company_id', '!=', $companyId)
                ->exists();
        })->values();

        $viaSubscription = Ticket::withTrashed()
            ->whereHas(
                'subscription',
                fn ($q) => $q->withTrashed()->where('company_id', $companyId),
            )
            ->pluck('id');

        $viaExclusiveContacts = $exclusiveContactIds->isEmpty()
            ? collect()
            : Ticket::withTrashed()->whereIn('contact_id', $exclusiveContactIds)->pluck('id');

        return $viaSubscription->merge($viaExclusiveContacts)->unique()->values();
    }

    /**
     * @param  list<TicketDocument>  $documents
     */
    protected function deleteStoredFiles(array $documents): int
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
                Log::warning('Company purge: could not delete ticket document file', [
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
                Log::warning('Company purge: could not delete comment attachment', [
                    'path' => $comment->attachment_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * @param  list<CompanyChangeRequest>  $requests
     */
    protected function deleteChangeRequestFiles(array $requests): int
    {
        $removed = 0;
        foreach ($requests as $request) {
            foreach ([
                [$request->proposal_disk ?: 'local', $request->proposal_path],
                [$request->letter_disk ?: 'local', $request->letter_path],
            ] as [$disk, $path]) {
                if (! filled($path)) {
                    continue;
                }
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $removed++;
                    }
                } catch (Throwable $e) {
                    Log::warning('Company purge: could not delete change-request file', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $removed;
    }
}
