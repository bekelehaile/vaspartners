<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceFinalApprover;
use App\Models\ServiceRequisitionDocument;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketApprovalStep;
use App\Models\TicketAssignment;
use App\Models\TicketDocumentReview;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Support\TimestampPublicId;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Encodes the legacy MVAS ticket lifecycle on the new schema.
 *
 * open → assign → in_progress → doc review → approval chain → completed → closed
 *                                                                      ↘ rejected → re-verify
 */
class TicketWorkflowService
{
    /**
     * Request-local cache of service+requisition document matrices (list pages hit this per row).
     *
     * @var array<string, \Illuminate\Support\Collection<int, ServiceRequisitionDocument>>
     */
    protected static array $documentMatrixCache = [];

    public function __construct(
        protected SubscriptionLifecycleService $subscriptions,
        protected PartnerNotificationService $notifications,
        protected CompanyMembershipService $membership,
    ) {}

    public function transition(Ticket $ticket, TicketStatus $to, mixed $actor = null, ?string $note = null, array $meta = []): void
    {
        if ($actor instanceof User) {
            $this->assertAccountManagerMayProcessTicket($ticket, $actor);
        }

        $from = $ticket->status;
        $isReassignment = ! empty($meta['reassignment']);
        if ($from === $to && ! $isReassignment) {
            return;
        }

        // Open / In progress are not allowed while hard-required documents are missing.
        if (in_array($to, [TicketStatus::Open, TicketStatus::InProgress], true)
            && empty($meta['skip_document_assert'])) {
            $this->assertRequiredDocumentsUploaded($ticket);
        }

        // Completed approvals also require a full required document set.
        if ($to === TicketStatus::Completed && empty($meta['skip_document_assert'])) {
            $this->assertRequiredDocumentsUploaded($ticket);
        }

        $ticket->status = $to;
        $stampedAt = $this->applyStatusEventTimestamp($ticket, $from, $to);

        // Terminal / approved outcomes with complete docs cannot remain "Pending" review.
        if (in_array($to, [TicketStatus::Completed, TicketStatus::Closed], true)) {
            $attachState = $this->attachmentStatus($ticket)['state'] ?? null;
            $review = $ticket->document_review_status instanceof DocumentReviewStatus
                ? $ticket->document_review_status
                : DocumentReviewStatus::tryFrom((string) $ticket->document_review_status);

            if ($attachState === 'complete' && ($review === null || $review === DocumentReviewStatus::Pending)) {
                $ticket->document_review_status = DocumentReviewStatus::Passed;
            }
        }

        $ticket->save();

        $historyMeta = $meta;
        if (empty($historyMeta['event'])) {
            $historyMeta['event'] = match ($to) {
                TicketStatus::Open => $from === null ? 'submitted' : 'pending',
                TicketStatus::InProgress => ! empty($meta['reassignment']) ? 'reassigned' : 'in_progress',
                TicketStatus::Completed => 'approved',
                TicketStatus::Closed => 'closed',
                TicketStatus::Rejected => 'rejected',
            };
        }
        $historyMeta['status_stamp_column'] = $to->eventTimestampColumn();
        $historyMeta['status_stamped_at'] = $stampedAt->toIso8601String();

        TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->id,
            'note' => $note,
            'meta' => $historyMeta ?: null,
            'created_at' => $stampedAt,
        ]);

        if (
            empty($meta['skip_subscription_apply'])
            && (
                $to === TicketStatus::Completed
                || ($to === TicketStatus::Closed && $from !== TicketStatus::Completed)
            )
        ) {
            $this->subscriptions->applyFromTicket($ticket->fresh(['requisition', 'service', 'subscription']));
        }

        if (! empty($meta['skip_partner_notification'])) {
            return;
        }

        DB::afterCommit(function () use ($ticket, $from, $to, $note) {
            $this->notifications->ticketStatusChanged(
                $ticket->fresh(['contact', 'service', 'requisition']),
                $from,
                $to,
                $note,
            );
        });
    }

    /**
     * Stamp the denormalized event column for the target status and clear stamps that
     * no longer apply when a request is reopened.
     *
     * @return \Illuminate\Support\Carbon
     */
    protected function applyStatusEventTimestamp(Ticket $ticket, ?TicketStatus $from, TicketStatus $to): \Illuminate\Support\Carbon
    {
        $now = now();
        $column = $to->eventTimestampColumn();
        $ticket->{$column} = $now;

        // Re-entering Pending after rejection (or any reopen): clear outcome stamps.
        if ($to === TicketStatus::Open) {
            $ticket->rejected_at = null;
            $ticket->completed_at = null;
            $ticket->closed_at = null;
            // Keep assigned_at / in_progress_at history until a fresh assign overwrites them.
        }

        // Rejection supersedes a prior approval path on this cycle.
        if ($to === TicketStatus::Rejected) {
            $ticket->completed_at = null;
            $ticket->closed_at = null;
        }

        // First time moving into In progress without an assign() call — seed assigned_at.
        if ($to === TicketStatus::InProgress && $ticket->assigned_at === null) {
            $ticket->assigned_at = $now;
        }

        return $now;
    }

    /**
     * Partner corrected a rejected request — return it to the account manager
     * who handled it (In progress). If no handler was ever assigned, leave it
     * Pending (open) for the unassigned queue.
     */
    public function resubmitByContact(Ticket $ticket, Contact $contact): Ticket
    {
        return DB::transaction(function () use ($ticket, $contact) {
            $ticket->refresh();
            $ticket->loadMissing('service');

            $this->assertServiceAcceptsRequests($ticket->service);

            if ($ticket->status !== TicketStatus::Rejected) {
                return $ticket;
            }

            $this->assertRequiredDocumentsUploaded($ticket);

            $handlerId = $this->resolveAccountManagerUserId($ticket);
            $handler = $handlerId ? User::query()->find($handlerId) : null;
            if ($handler && ! $handler->isAssignableAccountManager()) {
                $handler = null;
            }

            $ticket->document_review_status = DocumentReviewStatus::Pending;
            $ticket->needs_reverification = false;
            $ticket->current_approver_user_id = null;
            if ($handler) {
                $ticket->assigned_to_user_id = $handler->id;
                $ticket->assigned_at = now();
            } else {
                // Previous handler inactive / not an Account Manager — release for claim.
                $ticket->assigned_to_user_id = null;
            }
            $ticket->save();

            $this->transition(
                $ticket,
                TicketStatus::Open,
                $contact,
                'Partner updated the request and submitted it for re-check',
                [
                    'event' => 'resubmitted',
                    'skip_partner_notification' => true,
                ],
            );

            if ($handler) {
                TicketAssignment::query()->create([
                    'ticket_id' => $ticket->id,
                    'assigned_by_user_id' => $handler->id,
                    'assigned_to_user_id' => $handler->id,
                    'priority_id' => $ticket->priority_id,
                    'note' => 'Auto-returned to account manager after partner resubmit',
                ]);

                $this->transition(
                    $ticket,
                    TicketStatus::InProgress,
                    $contact,
                    'Resubmitted — returned to account manager '.$handler->name,
                    [
                        'event' => 'assigned',
                        'assignee_user_id' => $handler->id,
                        'assignee_name' => $handler->name,
                        'source' => 'partner_resubmit',
                        'skip_partner_notification' => true,
                    ],
                );
            }

            $fresh = $ticket->fresh(['contact', 'service', 'requisition', 'assignee']);
            DB::afterCommit(function () use ($fresh) {
                $this->notifications->ticketResubmitted($fresh);
            });

            return $fresh;
        });
    }

    /**
     * Prefer the current assignee, else the latest assignment / doc-review AM —
     * only when that user is still an active Account Manager.
     */
    protected function resolveAccountManagerUserId(Ticket $ticket): ?int
    {
        $candidates = [];
        if (filled($ticket->assigned_to_user_id)) {
            $candidates[] = (int) $ticket->assigned_to_user_id;
        }

        $fromAssignment = TicketAssignment::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->value('assigned_to_user_id');
        if ($fromAssignment) {
            $candidates[] = (int) $fromAssignment;
        }

        $fromReview = TicketDocumentReview::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->value('reviewed_by_user_id');
        if ($fromReview) {
            $candidates[] = (int) $fromReview;
        }

        foreach (array_values(array_unique($candidates)) as $userId) {
            $user = User::query()->find($userId);
            if ($user?->isAssignableAccountManager()) {
                return (int) $user->id;
            }
        }

        return null;
    }

    public function requiredDocumentTypeIds(int $serviceId, int $requisitionId): array
    {
        return $this->hardRequiredDocumentRows($serviceId, $requisitionId)
            ->pluck('document_type_id')
            ->all();
    }

    public function hasRequiredDocuments(int $serviceId, int $requisitionId): bool
    {
        return count($this->requiredDocumentTypeIds($serviceId, $requisitionId)) > 0;
    }

    /**
     * Attachment completeness for admin: complete | incomplete | none_required.
     *
     * @return array{
     *   state: string,
     *   label: string,
     *   required_count: int,
     *   uploaded_count: int,
     *   missing_count: int,
     *   missing_ids: list<int>,
     *   missing_names: list<string>,
     *   received_names: list<string>,
     *   checklist: list<array{document_type_id: int, name: string, is_required: bool, received: bool}>
     * }
     */
    public function attachmentStatus(Ticket $ticket): array
    {
        $matrix = $this->documentMatrixFor(
            (int) $ticket->service_id,
            (int) $ticket->requisition_id,
        );

        if ($ticket->relationLoaded('documents')) {
            $uploadedIds = $ticket->documents
                ->pluck('document_type_id')
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->all();
        } else {
            $uploadedIds = $ticket->documents()
                ->pluck('document_type_id')
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        $uploadedSet = array_fill_keys($uploadedIds, true);

        $checklist = [];
        $missingIds = [];
        $missingNames = [];
        $receivedNames = [];
        $requiredCount = 0;
        $uploadedRequired = 0;

        foreach ($matrix as $row) {
            $type = $row->documentType;
            if (! $type) {
                continue;
            }
            $hardRequired = (bool) $row->is_required && ! $this->isSoftOptionalDocumentType($type);
            $received = isset($uploadedSet[(int) $type->id]);
            $checklist[] = [
                'document_type_id' => (int) $type->id,
                'name' => $type->name,
                'is_required' => $hardRequired,
                'received' => $received,
            ];
            if ($hardRequired) {
                $requiredCount++;
                if ($received) {
                    $uploadedRequired++;
                    $receivedNames[] = $type->name;
                } else {
                    $missingIds[] = (int) $type->id;
                    $missingNames[] = $type->name;
                }
            } elseif ($received) {
                $receivedNames[] = $type->name;
            }
        }

        if ($requiredCount === 0) {
            $state = 'none_required';
            $label = 'No required docs';
        } elseif ($missingIds === []) {
            $state = 'complete';
            $label = 'All required docs';
        } else {
            $state = 'incomplete';
            $label = 'Missing '.count($missingIds).' required';
        }

        return [
            'state' => $state,
            'label' => $label,
            'required_count' => $requiredCount,
            'uploaded_count' => $uploadedRequired,
            'missing_count' => count($missingIds),
            'missing_ids' => $missingIds,
            'missing_names' => $missingNames,
            'received_names' => $receivedNames,
            'checklist' => $checklist,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, ServiceRequisitionDocument>
     */
    protected function documentMatrixFor(int $serviceId, int $requisitionId)
    {
        $key = $serviceId.':'.$requisitionId;
        if (isset(static::$documentMatrixCache[$key])) {
            return static::$documentMatrixCache[$key];
        }

        return static::$documentMatrixCache[$key] = ServiceRequisitionDocument::query()
            ->with('documentType')
            ->where('service_id', $serviceId)
            ->where('requisition_id', $requisitionId)
            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function assertRequiredDocumentsUploaded(Ticket $ticket): void
    {
        $status = $this->attachmentStatus($ticket);
        if ($status['state'] !== 'incomplete') {
            return;
        }

        throw ValidationException::withMessages([
            'documents' => 'Required documents are missing for this service request: '.implode(', ', $status['missing_names']),
            'missing_document_type_ids' => $status['missing_ids'],
        ]);
    }

    /**
     * Statuses that must not stay open while hard-required docs are missing.
     *
     * Closed / Completed are excluded: legacy MVAS tickets often lack today's matrix,
     * and an already-activated service must not be flipped to Rejected/Failed review.
     *
     * @return list<TicketStatus>
     */
    public function statusesThatMustRejectWhenIncomplete(): array
    {
        return [
            TicketStatus::Open,
            TicketStatus::InProgress,
        ];
    }

    /**
     * True when this request already backs (or is linked to) a live subscription.
     * Old-system doc gaps must not reject or fail review in that case.
     */
    public function ticketHasAliveService(Ticket $ticket): bool
    {
        $ticket->loadMissing('subscription');

        $linked = $ticket->subscription;
        if ($linked instanceof Subscription && $linked->status instanceof SubscriptionStatus && $linked->status->isAlive()) {
            return true;
        }

        $activated = Subscription::query()
            ->where('activated_by_ticket_id', $ticket->id)
            ->get(['id', 'status']);

        foreach ($activated as $subscription) {
            if ($subscription->status instanceof SubscriptionStatus && $subscription->status->isAlive()) {
                return true;
            }
        }

        if (! $ticket->service_id || ! $ticket->contact_id) {
            return false;
        }

        $alive = array_map(
            fn (SubscriptionStatus $s) => $s->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isAlive()),
        );

        // Same partner contact + service with a live subscription (legacy link gaps).
        if (
            Subscription::query()
                ->where('contact_id', $ticket->contact_id)
                ->where('service_id', $ticket->service_id)
                ->whereIn('status', $alive)
                ->exists()
        ) {
            return true;
        }

        $companyId = Contact::query()->whereKey($ticket->contact_id)->value('current_company_id');
        if (! $companyId) {
            return false;
        }

        return Subscription::query()
            ->where('company_id', $companyId)
            ->where('service_id', $ticket->service_id)
            ->whereIn('status', $alive)
            ->exists();
    }

    /**
     * System / schedule / on-read: if an open/in-progress request is missing hard-required
     * docs, force Rejected. Never touches finished tickets that already have an active service.
     *
     * @return array{rejected: bool, skipped: bool, reason?: string, missing_names?: list<string>}
     */
    public function rejectForIncompleteDocuments(Ticket $ticket, bool $notify = true): array
    {
        return DB::transaction(function () use ($ticket, $notify) {
            $ticket->refresh();

            if (! in_array($ticket->status, $this->statusesThatMustRejectWhenIncomplete(), true)) {
                return [
                    'rejected' => false,
                    'skipped' => true,
                    'reason' => 'status_'.$ticket->status?->value,
                ];
            }

            // Active service wins over incomplete legacy / matrix docs.
            if ($this->ticketHasAliveService($ticket)) {
                return [
                    'rejected' => false,
                    'skipped' => true,
                    'reason' => 'alive_service',
                ];
            }

            $status = $this->attachmentStatus($ticket);
            if ($status['state'] !== 'incomplete') {
                return [
                    'rejected' => false,
                    'skipped' => true,
                    'reason' => $status['state'],
                ];
            }

            $missingNames = $status['missing_names'];
            $note = 'Automated document check: missing required documents — '.implode(', ', $missingNames);

            $ticket->document_review_status = DocumentReviewStatus::Failed;
            $ticket->needs_reverification = true;
            $ticket->current_approver_user_id = null;
            $ticket->save();

            $this->transition(
                $ticket,
                TicketStatus::Rejected,
                null,
                $note,
                [
                    'skip_partner_notification' => true,
                    'skip_document_assert' => true,
                    'source' => 'vas:scan-document-missing',
                    'missing_document_type_ids' => $status['missing_ids'],
                ],
            );

            if ($notify) {
                $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
                DB::afterCommit(function () use ($fresh) {
                    $this->notifications->documentsIncompleteAutoRejected($fresh);
                });
            }

            return [
                'rejected' => true,
                'skipped' => false,
                'missing_names' => $missingNames,
            ];
        });
    }

    /**
     * Undo auto-rejects that left an active service looking "rejected / review failed".
     *
     * @return array{restored: bool, skipped: bool, reason?: string, to_status?: string}
     */
    public function restoreAutoRejectWhenServiceAlive(Ticket $ticket): array
    {
        return DB::transaction(function () use ($ticket) {
            $ticket->refresh();

            if ($ticket->status !== TicketStatus::Rejected) {
                return ['restored' => false, 'skipped' => true, 'reason' => 'not_rejected'];
            }

            if (! $this->ticketHasAliveService($ticket)) {
                return ['restored' => false, 'skipped' => true, 'reason' => 'no_alive_service'];
            }

            $autoReject = TicketStatusHistory::query()
                ->where('ticket_id', $ticket->id)
                ->where('to_status', TicketStatus::Rejected->value)
                ->where(function ($q) {
                    $q->where('note', 'like', 'Automated document check:%')
                        ->orWhere('meta->source', 'vas:scan-document-missing');
                })
                ->orderByDesc('id')
                ->first();

            if (! $autoReject) {
                return ['restored' => false, 'skipped' => true, 'reason' => 'not_auto_doc_reject'];
            }

            $from = $autoReject->from_status instanceof TicketStatus
                ? $autoReject->from_status
                : TicketStatus::tryFrom((string) $autoReject->from_status);

            $to = in_array($from, [TicketStatus::Closed, TicketStatus::Completed], true)
                ? $from
                : TicketStatus::Closed;

            $ticket->forceFill([
                'document_review_status' => DocumentReviewStatus::Passed,
                'needs_reverification' => false,
                'rejected_at' => null,
            ])->save();

            $this->transition(
                $ticket,
                $to,
                null,
                'Restored: service is active — incomplete legacy documents must not keep the request rejected or review failed.',
                [
                    'skip_partner_notification' => true,
                    'skip_document_assert' => true,
                    'skip_subscription_apply' => true,
                    'source' => 'vas:restore-active-service-doc-rejects',
                ],
            );

            return [
                'restored' => true,
                'skipped' => false,
                'to_status' => $to->value,
            ];
        });
    }

    /**
     * Keep status consistent: incomplete hard-required docs → Rejected (open/in-progress only).
     * Safe to call on portal/admin reads.
     */
    public function enforceIncompleteMustBeRejected(Ticket $ticket, bool $notify = true): Ticket
    {
        $result = $this->rejectForIncompleteDocuments($ticket, $notify);

        return $result['rejected']
            ? $ticket->fresh(['contact', 'service', 'requisition', 'documents.documentType']) ?? $ticket
            : $ticket;
    }

    /**
     * Hard gate before create: every hard-required matrix type must be present in the upload set.
     *
     * @param  list<int>  $providedDocumentTypeIds
     */
    public function assertProvidedDocumentsCoverRequirements(
        int $serviceId,
        int $requisitionId,
        array $providedDocumentTypeIds,
    ): void {
        $required = $this->hardRequiredDocumentRows($serviceId, $requisitionId);
        if ($required->isEmpty()) {
            return;
        }

        $provided = [];
        foreach ($providedDocumentTypeIds as $id) {
            $provided[(int) $id] = true;
        }

        $missingNames = [];
        $missingIds = [];
        foreach ($required as $row) {
            $typeId = (int) $row->document_type_id;
            if (isset($provided[$typeId])) {
                continue;
            }
            $missingIds[] = $typeId;
            $missingNames[] = $row->documentType?->name ?: ('Document #'.$typeId);
        }

        if ($missingNames === []) {
            return;
        }

        throw ValidationException::withMessages([
            'documents' => 'Required documents are missing for this service request: '.implode(', ', $missingNames),
            'missing_document_type_ids' => $missingIds,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, ServiceRequisitionDocument> */
    protected function hardRequiredDocumentRows(int $serviceId, int $requisitionId)
    {
        return $this->documentMatrixFor($serviceId, $requisitionId)
            ->filter(function (ServiceRequisitionDocument $row) {
                return $row->is_required
                    && $row->documentType
                    && ! $this->isSoftOptionalDocumentType($row->documentType);
            })
            ->values();
    }

    protected function isSoftOptionalDocumentType(\App\Models\DocumentType $type): bool
    {
        if ($type->code === 'document-if-any') {
            return true;
        }

        return (bool) preg_match('/if any/i', (string) $type->name);
    }

    public function createTicket(Contact $contact, array $data): Ticket
    {
        $run = function () use ($contact, $data): Ticket {
            return DB::transaction(function () use ($contact, $data) {
                $service = Service::query()->findOrFail($data['service_id']);
                $requisition = Requisition::query()->findOrFail($data['requisition_id']);

                $this->assertServiceAcceptsRequests($service);

                // Hard gate: no VAS service requests until the company TIN number is admin-approved.
                if (empty($data['skip_open_limit'])) {
                    $this->membership->assertCanAccessCompany($contact);
                    $this->membership->assertHasPermission(
                        $contact,
                        $requisition->creates_subscription
                            ? \App\Enums\CompanyMemberPermission::CreateSubscriptions
                            : \App\Enums\CompanyMemberPermission::ManageServices,
                    );
                }

                if (! $service->requisitions()->where('requisitions.id', $requisition->id)->exists()) {
                    throw ValidationException::withMessages([
                        'requisition_id' => 'This request type is not enabled for the selected service.',
                    ]);
                }

                $this->assertOpenTicketLimit($contact, $requisition, $data);

                $this->subscriptions->assertTicketAllowed($contact, $data, $requisition, $service);

                // Final approver is optional: if configured for this service + type, the
                // approval chain runs; if not, AM closes after docs (no hard block on create).

                $subscriptionId = $data['subscription_id'] ?? null;
                if (! $subscriptionId && ($requisition->requires_active_subscription || $requisition->renews_subscription || $requisition->terminates_subscription)) {
                    $subscriptionId = \App\Models\Subscription::query()
                        ->where('company_id', $contact->company_id)
                        ->where('service_id', $service->id)
                        ->whereIn('status', ['active', 'pending_renewal', 'grace'])
                        ->latest('id')
                        ->value('id');
                }

                $openedAt = now();
                $ticket = Ticket::query()->create([
                    'tt_number' => $this->generateTtNumber(),
                    'contact_id' => $contact->id,
                    'service_id' => $data['service_id'],
                    'requisition_id' => $data['requisition_id'],
                    'subscription_id' => $subscriptionId,
                    'parent_ticket_id' => $data['parent_ticket_id'] ?? null,
                    'category_id' => $data['category_id'] ?? $service->category_id,
                    'region_id' => $data['region_id'] ?? null,
                    'zone_id' => $data['zone_id'] ?? null,
                    'woreda_id' => $data['woreda_id'] ?? null,
                    'building' => $data['building'] ?? null,
                    'location' => $data['location'] ?? null,
                    'description' => $data['description'] ?? null,
                    'status' => TicketStatus::Open,
                    'opened_at' => $openedAt,
                    'document_review_status' => DocumentReviewStatus::Pending,
                ]);

                TicketStatusHistory::query()->create([
                    'ticket_id' => $ticket->id,
                    'from_status' => null,
                    'to_status' => TicketStatus::Open->value,
                    'actor_type' => Contact::class,
                    'actor_id' => $contact->id,
                    'note' => 'Request submitted',
                    'meta' => [
                        'event' => 'submitted',
                        'status_stamp_column' => TicketStatus::Open->eventTimestampColumn(),
                        'status_stamped_at' => $openedAt->toIso8601String(),
                    ],
                    'created_at' => $openedAt,
                ]);

                $fresh = $ticket->fresh(['contact', 'service', 'requisition']);

                // Portal create-with-docs defers notify until all required files are stored.
                if (empty($data['skip_notification'])) {
                    DB::afterCommit(function () use ($fresh) {
                        $this->notifications->ticketSubmitted($fresh);
                    });
                }

                return $fresh;
            });
        };

        if (! empty($data['skip_create_lock'])) {
            return $run();
        }

        return $this->withTicketCreateLock(
            $contact,
            (int) ($data['service_id'] ?? 0),
            (int) ($data['requisition_id'] ?? 0),
            $run,
        );
    }

    /**
     * Partner portal create: ticket + required documents in one transaction.
     * Rolls back (no open orphan request) if any hard-required doc is missing or invalid.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{document_type_id: int, file: \Illuminate\Http\UploadedFile}>  $uploads
     */
    public function createTicketWithDocuments(
        Contact $contact,
        array $data,
        array $uploads,
        TicketDocumentService $documents,
    ): Ticket {
        $serviceId = (int) $data['service_id'];
        $requisitionId = (int) $data['requisition_id'];

        return $this->withTicketCreateLock($contact, $serviceId, $requisitionId, function () use ($contact, $data, $uploads, $documents) {
            return DB::transaction(function () use ($contact, $data, $uploads, $documents) {
                $serviceId = (int) $data['service_id'];
                $requisitionId = (int) $data['requisition_id'];
                $providedTypeIds = array_map(
                    static fn (array $row): int => (int) $row['document_type_id'],
                    $uploads,
                );

                // Fail before create so partners never see a Pending row without docs.
                $this->assertProvidedDocumentsCoverRequirements($serviceId, $requisitionId, $providedTypeIds);

                foreach ($uploads as $index => $upload) {
                    $type = $documents->resolveDocumentTypeForMatrix(
                        $serviceId,
                        $requisitionId,
                        (int) $upload['document_type_id'],
                    );
                    $documents->assertFileMatchesDocumentType($upload['file'], $type);
                }

                $data['skip_notification'] = true;
                $data['skip_create_lock'] = true;
                $ticket = $this->createTicket($contact, $data);

                foreach ($uploads as $upload) {
                    $documents->storeForContact(
                        $ticket,
                        $contact,
                        (int) $upload['document_type_id'],
                        $upload['file'],
                    );
                }

                $ticket->unsetRelation('documents');
                $this->assertRequiredDocumentsUploaded($ticket->fresh(['documents.documentType']));

                $fresh = $ticket->fresh(['contact', 'service', 'requisition', 'documents.documentType']);

                DB::afterCommit(function () use ($fresh) {
                    $this->notifications->ticketSubmitted($fresh);
                });

                return $fresh;
            });
        });
    }

    /**
     * Serialize creates for the same company + service + request type (anti race / double-submit).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withTicketCreateLock(Contact $contact, int $serviceId, int $requisitionId, callable $callback): mixed
    {
        $companyId = (int) ($contact->current_company_id ?? $contact->company_id ?? 0);
        if ($companyId < 1 || $serviceId < 1 || $requisitionId < 1) {
            return $callback();
        }

        $seconds = max(5, (int) config('vas.ticket_create.lock_seconds', 20));
        $wait = max(1, (int) config('vas.ticket_create.lock_wait_seconds', 8));
        $lock = Cache::lock("ticket-create:{$companyId}:{$serviceId}:{$requisitionId}", $seconds);

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'ticket' => 'Another request for this service is being submitted. Please wait a moment and try again.',
            ]);
        }

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Cap concurrent *new subscription* tickets per company + service.
     * Manage / renew / terminate are not limited here. Other services stay available.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertOpenTicketLimit(Contact $contact, Requisition $requisition, array $data): void
    {
        if (! empty($data['skip_open_limit'])) {
            return;
        }

        // Manage journeys are not capped by max_open_tickets.
        if (! $requisition->creates_subscription) {
            return;
        }

        if ($this->isExemptFromOpenTicketLimit($contact)) {
            return;
        }

        $serviceId = (int) ($data['service_id'] ?? 0);
        if ($serviceId < 1) {
            return;
        }

        $maxOpen = (int) config('vas.max_open_tickets', 1);
        $companyId = (int) $contact->company_id;
        $openCount = Ticket::query()
            ->where('service_id', $serviceId)
            ->where('status', TicketStatus::Open)
            ->whereHas('requisition', fn ($q) => $q->where('creates_subscription', true))
            ->when(
                $companyId > 0,
                fn ($q) => $q->whereHas('contact.memberships', fn ($cq) => $cq->where('company_id', $companyId)),
                fn ($q) => $q->where('contact_id', $contact->id),
            )
            ->count();

        if ($openCount >= $maxOpen) {
            throw ValidationException::withMessages([
                'ticket' => "Your company already has the maximum of {$maxOpen} open subscription request(s) for this service. You can still submit a new subscription for other services, or manage requests for this one.",
            ]);
        }
    }

    protected function isExemptFromOpenTicketLimit(Contact $contact): bool
    {
        $exempt = config('vas.open_ticket_limit_exempt_phones', []);
        if (! is_array($exempt) || $exempt === []) {
            return false;
        }

        $digits = \App\Support\PhoneNumber::normalize((string) $contact->phone_number);
        if ($digits === '') {
            return false;
        }

        return in_array($digits, $exempt, true);
    }

    public function assign(Ticket $ticket, User $assigner, User $assignee, ?int $priorityId = null, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $assigner, $assignee, $priorityId, $note) {
            if (! $assignee->isAssignableAccountManager()) {
                throw ValidationException::withMessages([
                    'assigned_to_user_id' => 'Select an active user with the Account Manager role.',
                ]);
            }

            $company = $ticket->serviceCompany();
            // AM cannot take / be given work for companies without a verified TIN.
            $assigner->assertCanHandleCompanyServices($company);
            $assignee->assertCanHandleCompanyServices($company);

            // New-subscription work must have a final approver before it enters AM backlog.
            if ($this->ticketRequiresApprovalChain($ticket)) {
                $this->assertFinalApproverConfigured((int) $ticket->service_id, (int) $ticket->requisition_id);
            }

            $this->assertRequiredDocumentsUploaded($ticket);

            $ticket->assigned_to_user_id = $assignee->id;
            // Handler assignment clock (separate from in_progress_at status stamp).
            $ticket->assigned_at = now();
            $ticket->priority_id = $priorityId ?? $ticket->priority_id;
            $ticket->escalated_at = now();
            $ticket->current_approver_user_id = null;
            $ticket->save();

            TicketAssignment::query()->create([
                'ticket_id' => $ticket->id,
                'assigned_by_user_id' => $assigner->id,
                'assigned_to_user_id' => $assignee->id,
                'priority_id' => $priorityId,
                'note' => $note,
            ]);

            if ($ticket->status === TicketStatus::Open) {
                $this->transition(
                    $ticket,
                    TicketStatus::InProgress,
                    $assigner,
                    $note ?? ('Assigned to '.$assignee->name),
                    [
                        'event' => 'assigned',
                        'assignee_user_id' => $assignee->id,
                        'assignee_name' => $assignee->name,
                        'assigner_user_id' => $assigner->id,
                        'assigner_name' => $assigner->name,
                    ],
                );
            } elseif ($ticket->status === TicketStatus::InProgress) {
                // Reassignment while already in progress: refresh in_progress_at with history.
                $this->transition(
                    $ticket,
                    TicketStatus::InProgress,
                    $assigner,
                    $note ?? ('Reassigned to '.$assignee->name),
                    [
                        'event' => 'reassigned',
                        'reassignment' => true,
                        'skip_partner_notification' => true,
                        'assignee_user_id' => $assignee->id,
                        'assignee_name' => $assignee->name,
                        'assigner_user_id' => $assigner->id,
                        'assigner_name' => $assigner->name,
                    ],
                );
            }

            return $ticket->fresh();
        });
    }

    public function reviewDocuments(
        Ticket $ticket,
        User $reviewer,
        DocumentReviewStatus $result,
        ?string $note = null,
        bool $notifyPartner = false,
        bool $closeAfterPass = false,
    ): Ticket {
        if (! in_array($result, [DocumentReviewStatus::Passed, DocumentReviewStatus::Failed], true)) {
            throw new InvalidArgumentException('Document review must be passed or failed.');
        }

        $this->assertAccountManagerMayProcessTicket($ticket, $reviewer);

        return DB::transaction(function () use ($ticket, $reviewer, $result, $note, $notifyPartner, $closeAfterPass) {
            if ($ticket->assigned_to_user_id !== $reviewer->id) {
                throw ValidationException::withMessages(['ticket' => 'Only the assigned account manager can verify documents.']);
            }

            TicketDocumentReview::query()->create([
                'ticket_id' => $ticket->id,
                'reviewed_by_user_id' => $reviewer->id,
                'result' => $result->value,
                'note' => $note,
            ]);

            $ticket->document_review_status = $result;
            $ticket->needs_reverification = false;

            $comment = app(TicketCommentService::class)
                ->postStaffDecisionNote($ticket, $reviewer, $note, $notifyPartner);

            if ($result === DocumentReviewStatus::Failed) {
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
                $ticket->save();

                $this->transition(
                    $ticket,
                    TicketStatus::Rejected,
                    $reviewer,
                    $note ?? 'Documents need correction by the partner',
                    [
                        'skip_partner_notification' => ! $notifyPartner,
                    ],
                );

                if ($notifyPartner) {
                    $notifyTicket = $ticket;
                    $notifyNote = $note;
                    $notifyComment = $comment;
                    DB::afterCommit(function () use ($notifyTicket, $notifyNote, $reviewer, $notifyComment) {
                        $fresh = $notifyTicket->fresh(['contact', 'service', 'requisition']);
                        $this->notifications->documentsNeedAttention($fresh, $notifyNote);
                        if ($notifyComment) {
                            $this->notifications->ticketMessagePosted($fresh, $reviewer, $notifyComment);
                        }
                    });
                }

                return $ticket->fresh(['contact', 'service', 'requisition']);
            }

            // Passed — partner must have uploaded every hard-required attachment first
            // (SMS Premium, Voice Premium, VISP, Collocation, etc.).
            $this->assertRequiredDocumentsUploaded($ticket);

            // Passed — resume handling after partner resubmit (open) or correction (rejected).
            if (in_array($ticket->status, [TicketStatus::Open, TicketStatus::Rejected], true)) {
                $this->transition(
                    $ticket,
                    TicketStatus::InProgress,
                    $reviewer,
                    $note ?? ($ticket->status === TicketStatus::Open
                        ? 'Documents verified after partner resubmit — continuing review'
                        : 'Documents re-verified — continuing review'),
                    [
                        'skip_partner_notification' => ! $notifyPartner,
                    ],
                );
            }

            // After-sales (non-new-subscription): docs satisfied is enough — AM can close, no approval chain.
            if (! $this->ticketRequiresApprovalChain($ticket)) {
                $ticket->current_approver_user_id = null;
                $ticket->save();

                // Optional one-step close when AM ticks "Close request" on Pass.
                if ($closeAfterPass) {
                    $this->assertAfterSalesReadyToClose($ticket);
                    $this->transition(
                        $ticket,
                        TicketStatus::Closed,
                        $reviewer,
                        $note ?? 'Documents verified — request closed',
                        [
                            'skip_partner_notification' => ! $notifyPartner,
                            'event' => 'closed',
                            'closed_after_doc_pass' => true,
                        ],
                    );

                    if ($notifyPartner && $comment) {
                        $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
                        DB::afterCommit(function () use ($fresh, $reviewer, $comment) {
                            $this->notifications->ticketMessagePosted($fresh, $reviewer, $comment);
                        });
                    }

                    return $ticket->fresh(['contact', 'service', 'requisition']);
                }

                $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
                $notifyNote = $note;
                $notifyComment = $comment;
                DB::afterCommit(function () use ($fresh, $notifyNote, $notifyPartner, $reviewer, $notifyComment) {
                    if ($notifyPartner) {
                        $this->notifications->documentsPassed($fresh, $notifyNote);
                        if ($notifyComment) {
                            $this->notifications->ticketMessagePosted($fresh, $reviewer, $notifyComment);
                        }
                    }
                });

                return $fresh;
            }

            $this->assertFinalApproverConfigured((int) $ticket->service_id, (int) $ticket->requisition_id);
            $nextApprover = $this->resolveNextApprover($reviewer, $ticket);

            $ticket->current_approver_user_id = $nextApprover->id;
            $ticket->save();

            $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
            $approverId = $nextApprover->id;
            $notifyNote = $note;
            $notifyComment = $comment;
            DB::afterCommit(function () use ($fresh, $approverId, $notifyNote, $notifyPartner, $reviewer, $notifyComment) {
                if ($notifyPartner) {
                    $this->notifications->documentsPassed($fresh, $notifyNote);
                    if ($notifyComment) {
                        $this->notifications->ticketMessagePosted($fresh, $reviewer, $notifyComment);
                    }
                }

                $approver = User::query()->find($approverId);
                if ($approver) {
                    $this->notifications->approvalNeeded($fresh, $approver);
                }
            });

            return $fresh;
        });
    }

    public function decide(
        Ticket $ticket,
        User $approver,
        ApprovalAction $action,
        ?string $note = null,
        bool $notifyPartner = false,
    ): Ticket {
        if ($action === ApprovalAction::Rejected && blank(trim((string) $note))) {
            throw ValidationException::withMessages([
                'note' => 'A reason is required when rejecting a request.',
            ]);
        }

        $this->assertAccountManagerMayProcessTicket($ticket, $approver);

        return DB::transaction(function () use ($ticket, $approver, $action, $note, $notifyPartner) {
            if ($ticket->current_approver_user_id !== $approver->id) {
                throw ValidationException::withMessages(['ticket' => 'You are not the current approver for this ticket.']);
            }

            $isFinal = $this->isFinalApprover($ticket, $approver);
            $docStatus = $ticket->document_review_status;

            $escalatedTo = null;
            $nextStatus = null;

            if ($action === ApprovalAction::Approved && $docStatus === DocumentReviewStatus::Passed) {
                $this->assertRequiredDocumentsUploaded($ticket);

                if (! $this->ticketRequiresApprovalChain($ticket)) {
                    throw ValidationException::withMessages([
                        'ticket' => 'This after-sales request does not use the approval chain. The account manager can close it after documents are satisfied.',
                    ]);
                }

                $this->assertFinalApproverConfigured((int) $ticket->service_id, (int) $ticket->requisition_id);

                if ($isFinal) {
                    $nextStatus = TicketStatus::Completed;
                    $ticket->current_approver_user_id = null;
                } else {
                    $next = $this->resolveNextApprover($approver, $ticket);
                    $escalatedTo = $next->id;
                    $ticket->current_approver_user_id = $escalatedTo;
                    $nextStatus = TicketStatus::InProgress;
                }
            } elseif ($action === ApprovalAction::Approved && $docStatus === DocumentReviewStatus::Failed) {
                $nextStatus = TicketStatus::Rejected;
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
            } elseif ($action === ApprovalAction::Rejected && $docStatus === DocumentReviewStatus::Passed) {
                // Send back to partner to fix documents
                $ticket->document_review_status = DocumentReviewStatus::Failed;
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
                $nextStatus = TicketStatus::Rejected;
            } else {
                // Rejected + Failed (or any incomplete-docs path): never complete without required docs.
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
                $nextStatus = TicketStatus::Rejected;
            }

            // MVAS: hand-off to next approver is always internal (no client notify).
            if ($escalatedTo !== null && $action === ApprovalAction::Approved) {
                $notifyPartner = false;
            }

            TicketApprovalStep::query()->create([
                'ticket_id' => $ticket->id,
                'approver_user_id' => $approver->id,
                'action' => $action,
                'document_review_snapshot' => $docStatus->value,
                'is_final' => $isFinal && $nextStatus === TicketStatus::Completed,
                'escalated_to_user_id' => $escalatedTo,
                'note' => $note,
            ]);

            $comment = app(TicketCommentService::class)
                ->postStaffDecisionNote($ticket, $approver, $note, $notifyPartner);

            $ticket->save();
            if ($nextStatus) {
                $this->transition($ticket, $nextStatus, $approver, $note, [
                    'event' => $action === ApprovalAction::Approved && $nextStatus === TicketStatus::Completed
                        ? 'approved'
                        : ($action === ApprovalAction::Rejected || $nextStatus === TicketStatus::Rejected
                            ? 'rejected'
                            : 'in_progress'),
                    'approval_action' => $action->value,
                    'approver_user_id' => $approver->id,
                    'approver_name' => $approver->name,
                    'is_final' => $isFinal && $nextStatus === TicketStatus::Completed,
                    'escalated_to_user_id' => $escalatedTo,
                    'document_review_snapshot' => $docStatus->value,
                    'skip_partner_notification' => ! $notifyPartner,
                    'notify_partner' => $notifyPartner,
                ]);
            }

            $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
            if ($escalatedTo) {
                DB::afterCommit(function () use ($fresh, $escalatedTo) {
                    $next = User::query()->find($escalatedTo);
                    if ($next) {
                        $this->notifications->approvalNeeded($fresh, $next);
                    }
                });
            }

            if ($notifyPartner && $comment) {
                DB::afterCommit(function () use ($fresh, $approver, $comment) {
                    $this->notifications->ticketMessagePosted(
                        $fresh->fresh(['contact', 'service', 'requisition']) ?? $fresh,
                        $approver,
                        $comment,
                    );
                });
            }

            return $fresh;
        });
    }

    /**
     * Dispatcher reject: management / super admin can send a request back with a mandatory
     * reason that is always visible to the partner (portal notification + SMS + public note).
     */
    public function rejectByDispatcher(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'note' => 'A reason is required when rejecting a request.',
            ]);
        }

        if (! $actor->canRejectTicket()) {
            throw ValidationException::withMessages([
                'ticket' => 'You do not have permission to reject this request.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason) {
            if (! in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress], true)) {
                throw ValidationException::withMessages([
                    'ticket' => 'Only open or in-progress requests can be rejected by a dispatcher.',
                ]);
            }

            $ticket->document_review_status = DocumentReviewStatus::Failed;
            $ticket->current_approver_user_id = null;
            $ticket->needs_reverification = true;
            $ticket->save();

            app(TicketCommentService::class)
                ->postStaffDecisionNote($ticket, $actor, $reason, notifyPartner: true);

            $this->transition($ticket, TicketStatus::Rejected, $actor, $reason, [
                'event' => 'rejected',
                'dispatcher_reject' => true,
                'actor_user_id' => $actor->id,
                'actor_name' => $actor->name,
                'notify_partner' => true,
            ]);

            return $ticket->fresh(['contact', 'service', 'requisition']);
        });
    }

    public function close(Ticket $ticket, User $actor, ?string $note = null, bool $notifyPartner = false): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $note, $notifyPartner) {
            $this->assertAccountManagerMayProcessTicket($ticket, $actor);

            if ($ticket->assigned_to_user_id !== $actor->id && ! $actor->is_management) {
                throw ValidationException::withMessages(['ticket' => 'Only the assignee or a supervisor can close.']);
            }

            $ticket->loadMissing('requisition');

            if ($this->ticketRequiresApprovalChain($ticket)) {
                // New subscription: must complete final approval first.
                if ($ticket->status !== TicketStatus::Completed) {
                    throw ValidationException::withMessages([
                        'ticket' => 'New subscription requests must complete final approval before closing.',
                    ]);
                }

                $this->assertFinalApproverConfigured((int) $ticket->service_id, (int) $ticket->requisition_id);

                $hadFinalApproval = TicketApprovalStep::query()
                    ->where('ticket_id', $ticket->id)
                    ->where('is_final', true)
                    ->exists();

                if (! $hadFinalApproval && ! $actor->is_management) {
                    throw ValidationException::withMessages([
                        'ticket' => 'This request was not approved by a final approver. Complete the approval chain before closing.',
                    ]);
                }
            } else {
                // After-sales: AM closes when required docs are satisfied (no approval chain).
                if (! in_array($ticket->status, [TicketStatus::InProgress, TicketStatus::Completed], true)) {
                    throw ValidationException::withMessages([
                        'ticket' => 'After-sales requests can be closed only while in progress (after documents are satisfied).',
                    ]);
                }

                if (filled($ticket->current_approver_user_id)) {
                    throw ValidationException::withMessages([
                        'ticket' => 'This request is waiting on an approver. It cannot be closed yet.',
                    ]);
                }

                $this->assertAfterSalesReadyToClose($ticket);
            }

            $comment = app(TicketCommentService::class)
                ->postStaffDecisionNote($ticket, $actor, $note, $notifyPartner);

            $ticket->current_approver_user_id = null;
            $ticket->save();
            $this->transition($ticket, TicketStatus::Closed, $actor, $note ?? 'Ticket closed', [
                'event' => 'closed',
                'skip_partner_notification' => ! $notifyPartner,
            ]);

            if ($notifyPartner && $comment) {
                $fresh = $ticket->fresh(['contact', 'service', 'requisition']);
                DB::afterCommit(function () use ($fresh, $actor, $comment) {
                    $this->notifications->ticketMessagePosted($fresh, $actor, $comment);
                });
            }

            return $ticket->fresh();
        });
    }

    /**
     * Approval chain only when a final approver is configured for this service + request type.
     * If not configured → AM closes after docs (scalable default).
     * After-sales (creates_subscription=false) never use the chain.
     */
    public function ticketRequiresApprovalChain(Ticket $ticket): bool
    {
        $ticket->loadMissing('requisition');

        if (! $ticket->requisition?->creates_subscription) {
            return false;
        }

        if (! $ticket->service_id || ! $ticket->requisition_id) {
            return false;
        }

        return $this->hasFinalApproverConfigured(
            (int) $ticket->service_id,
            (int) $ticket->requisition_id,
        );
    }

    /**
     * Whether the given actor may close this ticket from the UI (single or bulk).
     */
    public function actorMayClose(Ticket $ticket, ?User $actor): bool
    {
        if (! $actor) {
            return false;
        }

        if ($ticket->assigned_to_user_id !== $actor->id && ! $actor->is_management) {
            return false;
        }

        try {
            $this->assertAccountManagerMayProcessTicket($ticket, $actor);
        } catch (ValidationException) {
            return false;
        }

        $ticket->loadMissing('requisition');

        if ($this->ticketRequiresApprovalChain($ticket)) {
            return $ticket->status === TicketStatus::Completed;
        }

        if (! in_array($ticket->status, [TicketStatus::InProgress, TicketStatus::Completed], true)) {
            return false;
        }

        if (filled($ticket->current_approver_user_id)) {
            return false;
        }

        try {
            $this->assertAfterSalesReadyToClose($ticket);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * After-sales close gate: required docs must be present and verified when the matrix requires them.
     */
    public function assertAfterSalesReadyToClose(Ticket $ticket): void
    {
        $attach = $this->attachmentStatus($ticket);
        $state = $attach['state'] ?? null;

        if ($state === 'incomplete') {
            throw ValidationException::withMessages([
                'ticket' => 'Required documents are missing. Ask the partner to upload them, then verify docs before closing.',
            ]);
        }

        if ($state === 'complete') {
            $review = $ticket->document_review_status instanceof DocumentReviewStatus
                ? $ticket->document_review_status
                : DocumentReviewStatus::tryFrom((string) $ticket->document_review_status);

            if ($review !== DocumentReviewStatus::Passed) {
                throw ValidationException::withMessages([
                    'ticket' => 'Verify documents (Pass) before closing this after-sales request.',
                ]);
            }
        }
    }

    /**
     * Soft notify only — AM is not blocked when company/TIN is missing or unverified.
     */
    public function assertAccountManagerMayProcessTicket(Ticket $ticket, User $actor): void
    {
        // No hard gate. UI surfaces {@see User::companyTinWarning()}.
    }

    public function accountManagerMayProcessTicket(Ticket $ticket, ?User $actor): bool
    {
        return $actor !== null;
    }

    public function companyTinWarningForTicket(Ticket $ticket, ?User $actor): ?string
    {
        if (! $actor) {
            return null;
        }

        return $actor->companyTinWarning($ticket->serviceCompany());
    }

    public function isFinalApprover(Ticket $ticket, User $user): bool
    {
        return ServiceFinalApprover::query()
            ->where('service_id', $ticket->service_id)
            ->where('requisition_id', $ticket->requisition_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Partner may edit/resubmit only while status allows it and the catalog service is active.
     */
    public function contactMayEditTicket(Ticket $ticket): bool
    {
        $ticket->loadMissing('service');

        if (! $ticket->status->allowsContactEdits()) {
            return false;
        }

        return (bool) ($ticket->service?->is_active);
    }

    public function assertContactMayEditTicket(Ticket $ticket): void
    {
        $ticket->loadMissing('service');

        if (! $ticket->status->allowsContactEdits()) {
            throw ValidationException::withMessages([
                'ticket' => 'This request cannot be edited while Ethio telecom is handling it. You can edit again if it is sent back for corrections.',
            ]);
        }

        $this->assertServiceAcceptsRequests($ticket->service);
    }

    public function assertServiceAcceptsRequests(?Service $service): void
    {
        if (! $service || ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => 'This service is deactivated. New or updated requests for it are not accepted.',
            ]);
        }
    }

    /**
     * Whether this service + request type has at least one active final approver.
     */
    public function hasFinalApproverConfigured(int $serviceId, int $requisitionId): bool
    {
        return ServiceFinalApprover::query()
            ->where('service_id', $serviceId)
            ->where('requisition_id', $requisitionId)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    /**
     * New-subscription service + request type must have a final approver.
     */
    public function assertFinalApproverConfigured(int $serviceId, int $requisitionId): void
    {
        if (! $this->hasFinalApproverConfigured($serviceId, $requisitionId)) {
            throw ValidationException::withMessages([
                'approver' => 'Final approver is not configured for this service and request type. Add one under Catalog → Services → Final approvers, or leave it unset so the account manager can close after documents.',
            ]);
        }
    }

    /**
     * Resolve the next active manager in the approval chain.
     */
    public function resolveNextApprover(User $from, Ticket $ticket): User
    {
        $managerId = $from->manager_id;
        if (! $managerId) {
            throw ValidationException::withMessages([
                'approver' => 'Next approver is not found. The current reviewer has no manager configured for the approval chain.',
            ]);
        }

        $next = User::query()
            ->whereKey($managerId)
            ->where('is_active', true)
            ->first();

        if (! $next) {
            throw ValidationException::withMessages([
                'approver' => 'Next approver is not found. The configured manager is missing or inactive.',
            ]);
        }

        // Guard against broken hierarchy loops before a final approver is reached.
        if ($this->isFinalApprover($ticket, $next)) {
            return $next;
        }

        // If this next person is not final and also has no further manager, fail early
        // unless they themselves are somehow final (already handled).
        if (! $next->manager_id && ! $this->isFinalApprover($ticket, $next)) {
            throw ValidationException::withMessages([
                'approver' => 'Next approver is not found. Approval cannot reach a final approver for this service and request type.',
            ]);
        }

        return $next;
    }

    /**
     * Unique request number: year+month+day+hour + two random digits.
     * Example: 202607230923
     */
    protected function generateTtNumber(): string
    {
        return TimestampPublicId::generate(
            now(),
            fn (string $number): bool => Ticket::query()->where('tt_number', $number)->exists(),
        );
    }
}
