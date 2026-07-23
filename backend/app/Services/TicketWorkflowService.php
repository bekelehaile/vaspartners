<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceFinalApprover;
use App\Models\ServiceRequisitionDocument;
use App\Models\Ticket;
use App\Models\TicketApprovalStep;
use App\Models\TicketAssignment;
use App\Models\TicketDocumentReview;
use App\Models\TicketStatusHistory;
use App\Models\User;
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
    public function __construct(
        protected SubscriptionLifecycleService $subscriptions,
        protected PartnerNotificationService $notifications,
    ) {}

    public function transition(Ticket $ticket, TicketStatus $to, mixed $actor = null, ?string $note = null, array $meta = []): void
    {
        $from = $ticket->status;
        if ($from === $to) {
            return;
        }

        $ticket->status = $to;
        match ($to) {
            TicketStatus::InProgress => $ticket->assigned_at ??= now(),
            TicketStatus::Completed => $ticket->completed_at = now(),
            TicketStatus::Rejected => $ticket->rejected_at = now(),
            TicketStatus::Closed => $ticket->closed_at = now(),
            default => null,
        };
        $ticket->save();

        TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->id,
            'note' => $note,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);

        if ($to === TicketStatus::Completed
            || ($to === TicketStatus::Closed && $from !== TicketStatus::Completed)) {
            $this->subscriptions->applyFromTicket($ticket->fresh(['requisition', 'service', 'subscription']));
        }

        DB::afterCommit(function () use ($ticket, $from, $to, $note) {
            $this->notifications->ticketStatusChanged(
                $ticket->fresh(['customer', 'service', 'requisition']),
                $from,
                $to,
                $note,
            );
        });
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
        $matrix = ServiceRequisitionDocument::query()
            ->with('documentType')
            ->where('service_id', $ticket->service_id)
            ->where('requisition_id', $ticket->requisition_id)
            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $uploadedIds = $ticket->documents()
            ->pluck('document_type_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();
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

    /** @return \Illuminate\Support\Collection<int, ServiceRequisitionDocument> */
    protected function hardRequiredDocumentRows(int $serviceId, int $requisitionId)
    {
        return ServiceRequisitionDocument::query()
            ->with('documentType')
            ->where('service_id', $serviceId)
            ->where('requisition_id', $requisitionId)
            ->where('is_required', true)
            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(function (ServiceRequisitionDocument $row) {
                return $row->documentType && ! $this->isSoftOptionalDocumentType($row->documentType);
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

    public function createTicket(Customer $customer, array $data): Ticket
    {
        return DB::transaction(function () use ($customer, $data) {
            $service = Service::query()->findOrFail($data['service_id']);
            $requisition = Requisition::query()->findOrFail($data['requisition_id']);

            if (! $service->requisitions()->where('requisitions.id', $requisition->id)->exists()) {
                throw ValidationException::withMessages([
                    'requisition_id' => 'This request type is not enabled for the selected service.',
                ]);
            }

            $this->assertOpenTicketLimit($customer, $requisition, $data);

            $this->subscriptions->assertTicketAllowed($customer, $data, $requisition, $service);

            if (empty($data['skip_open_limit']) && ! $customer->hasActiveCompanyMembership()) {
                throw ValidationException::withMessages([
                    'profile' => $customer->company_id && $customer->company_membership_active === false
                        ? 'Your membership for this company is disabled. Contact an administrator.'
                        : 'Please complete your company details before submitting a service request.',
                ]);
            }

            $subscriptionId = $data['subscription_id'] ?? null;
            if (! $subscriptionId && ($requisition->requires_active_subscription || $requisition->renews_subscription || $requisition->terminates_subscription)) {
                $subscriptionId = \App\Models\Subscription::query()
                    ->where('company_id', $customer->company_id)
                    ->where('service_id', $service->id)
                    ->whereIn('status', ['active', 'pending_renewal', 'grace'])
                    ->latest('id')
                    ->value('id');
            }

            $ticket = Ticket::query()->create([
                'tt_number' => $this->generateTtNumber(),
                'customer_id' => $customer->id,
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
                'document_review_status' => DocumentReviewStatus::Pending,
            ]);

            TicketStatusHistory::query()->create([
                'ticket_id' => $ticket->id,
                'from_status' => null,
                'to_status' => TicketStatus::Open->value,
                'actor_type' => Customer::class,
                'actor_id' => $customer->id,
                'note' => 'Ticket created',
                'created_at' => now(),
            ]);

            $fresh = $ticket->fresh(['customer', 'service', 'requisition']);

            DB::afterCommit(function () use ($fresh) {
                $this->notifications->ticketSubmitted($fresh);
            });

            return $fresh;
        });
    }

    /**
     * One open new-subscription ticket max. Manage / renew / terminate can coexist
     * (and multiple manage tickets for different services are allowed).
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertOpenTicketLimit(Customer $customer, Requisition $requisition, array $data): void
    {
        if (! empty($data['skip_open_limit'])) {
            return;
        }

        // Manage journeys are not capped by max_open_tickets.
        if (! $requisition->creates_subscription) {
            return;
        }

        $maxOpen = (int) config('vas.max_open_tickets', 1);
        $companyId = (int) $customer->company_id;
        $openCount = Ticket::query()
            ->where('status', TicketStatus::Open)
            ->whereHas('requisition', fn ($q) => $q->where('creates_subscription', true))
            ->when(
                $companyId > 0,
                fn ($q) => $q->whereHas('customer.memberships', fn ($cq) => $cq->where('company_id', $companyId)),
                fn ($q) => $q->where('customer_id', $customer->id),
            )
            ->count();

        if ($openCount >= $maxOpen) {
            throw ValidationException::withMessages([
                'ticket' => "Your company already has the maximum of {$maxOpen} open subscription request(s). You can still submit manage requests for other services.",
            ]);
        }
    }

    public function assign(Ticket $ticket, User $assigner, User $assignee, ?int $priorityId = null, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $assigner, $assignee, $priorityId, $note) {
            $ticket->assigned_to_user_id = $assignee->id;
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
                $this->transition($ticket, TicketStatus::InProgress, $assigner, $note ?? 'Assigned to account manager');
            }

            return $ticket->fresh();
        });
    }

    public function reviewDocuments(Ticket $ticket, User $reviewer, DocumentReviewStatus $result, ?string $note = null): Ticket
    {
        if (! in_array($result, [DocumentReviewStatus::Passed, DocumentReviewStatus::Failed], true)) {
            throw new InvalidArgumentException('Document review must be passed or failed.');
        }

        return DB::transaction(function () use ($ticket, $reviewer, $result, $note) {
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

            if ($result === DocumentReviewStatus::Failed) {
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
                $ticket->save();

                $this->transition(
                    $ticket,
                    TicketStatus::Rejected,
                    $reviewer,
                    $note ?? 'Documents need correction by the partner',
                );

                $notifyTicket = $ticket;
                $notifyNote = $note;
                DB::afterCommit(function () use ($notifyTicket, $notifyNote) {
                    $this->notifications->documentsNeedAttention(
                        $notifyTicket->fresh(['customer', 'service', 'requisition']),
                        $notifyNote,
                    );
                });

                return $ticket->fresh(['customer', 'service', 'requisition']);
            }

            // Passed — resume handling / start approval chain
            if ($ticket->status === TicketStatus::Rejected) {
                $this->transition(
                    $ticket,
                    TicketStatus::InProgress,
                    $reviewer,
                    $note ?? 'Documents re-verified — continuing review',
                );
            }

            if (! $this->hasRequiredDocuments($ticket->service_id, $ticket->requisition_id)) {
                // No docs required — AM can move toward close without approval chain.
                $ticket->current_approver_user_id = null;
                $ticket->save();

                return $ticket->fresh();
            }

            if (! $reviewer->manager_id) {
                throw ValidationException::withMessages([
                    'manager' => 'Account manager must have a manager configured for the approval chain.',
                ]);
            }

            $ticket->current_approver_user_id = $reviewer->manager_id;
            $ticket->save();

            $fresh = $ticket->fresh(['customer', 'service', 'requisition']);
            $approverId = $reviewer->manager_id;
            DB::afterCommit(function () use ($fresh, $approverId) {
                $approver = User::query()->find($approverId);
                if ($approver) {
                    $this->notifications->approvalNeeded($fresh, $approver);
                }
            });

            return $fresh;
        });
    }

    public function decide(Ticket $ticket, User $approver, ApprovalAction $action, ?string $note = null): Ticket
    {
        if ($action === ApprovalAction::Rejected && blank(trim((string) $note))) {
            throw ValidationException::withMessages([
                'note' => 'A reason is required when rejecting a request.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $approver, $action, $note) {
            if ($ticket->current_approver_user_id !== $approver->id) {
                throw ValidationException::withMessages(['ticket' => 'You are not the current approver for this ticket.']);
            }

            $isFinal = $this->isFinalApprover($ticket, $approver);
            $docStatus = $ticket->document_review_status;

            $escalatedTo = null;
            $nextStatus = null;

            if ($action === ApprovalAction::Approved && $docStatus === DocumentReviewStatus::Passed) {
                if ($isFinal) {
                    $nextStatus = TicketStatus::Completed;
                    $ticket->current_approver_user_id = null;
                } else {
                    $escalatedTo = $approver->manager_id;
                    if (! $escalatedTo) {
                        throw ValidationException::withMessages(['manager' => 'Non-final approver must have a manager.']);
                    }
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
            } else { // Rejected + Failed
                if ($isFinal) {
                    // Accept with missed docs path → complete (legacy matrix)
                    $nextStatus = TicketStatus::Completed;
                    $ticket->current_approver_user_id = null;
                } else {
                    $escalatedTo = $approver->manager_id;
                    if (! $escalatedTo) {
                        throw ValidationException::withMessages(['manager' => 'Non-final approver must have a manager.']);
                    }
                    $ticket->current_approver_user_id = $escalatedTo;
                    $nextStatus = TicketStatus::InProgress;
                }
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

            $ticket->save();
            if ($nextStatus) {
                $this->transition($ticket, $nextStatus, $approver, $note, [
                    'approval_action' => $action->value,
                    'approver_user_id' => $approver->id,
                    'approver_name' => $approver->name,
                    'is_final' => $isFinal && $nextStatus === TicketStatus::Completed,
                    'escalated_to_user_id' => $escalatedTo,
                    'document_review_snapshot' => $docStatus->value,
                ]);
            }

            $fresh = $ticket->fresh(['customer', 'service', 'requisition']);
            if ($escalatedTo) {
                DB::afterCommit(function () use ($fresh, $escalatedTo) {
                    $next = User::query()->find($escalatedTo);
                    if ($next) {
                        $this->notifications->approvalNeeded($fresh, $next);
                    }
                });
            }

            return $fresh;
        });
    }

    public function close(Ticket $ticket, User $actor, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $note) {
            if ($ticket->assigned_to_user_id !== $actor->id && ! $actor->is_management) {
                throw ValidationException::withMessages(['ticket' => 'Only the assignee or a supervisor can close.']);
            }

            $allowed = [TicketStatus::Completed, TicketStatus::InProgress];
            if (! in_array($ticket->status, $allowed, true)) {
                throw ValidationException::withMessages(['ticket' => 'Ticket cannot be closed from its current status.']);
            }

            // If docs required, must be completed first (unless no-doc path already in progress without approver)
            if ($this->hasRequiredDocuments($ticket->service_id, $ticket->requisition_id)
                && $ticket->status !== TicketStatus::Completed) {
                throw ValidationException::withMessages(['ticket' => 'Complete approval before closing.']);
            }

            $ticket->current_approver_user_id = null;
            $ticket->save();
            $this->transition($ticket, TicketStatus::Closed, $actor, $note ?? 'Ticket closed');

            return $ticket->fresh();
        });
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
     * Unique service-request id: year+month+day+hour + two random digits.
     * Example: 202607230923
     */
    protected function generateTtNumber(): string
    {
        $prefix = now()->format('YmdH');

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $number = $prefix.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);

            if (! Ticket::query()->where('tt_number', $number)->exists()) {
                return $number;
            }
        }

        // Same-hour collision fallback: add minutes so the id stays unique.
        return now()->format('YmdHi').str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
    }
}
