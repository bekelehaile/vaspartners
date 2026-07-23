<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Models\Client;
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
    }

    public function requiredDocumentTypeIds(int $serviceId, int $requisitionId): array
    {
        return ServiceRequisitionDocument::query()
            ->where('service_id', $serviceId)
            ->where('requisition_id', $requisitionId)
            ->where('is_required', true)
            ->pluck('document_type_id')
            ->all();
    }

    public function hasRequiredDocuments(int $serviceId, int $requisitionId): bool
    {
        return count($this->requiredDocumentTypeIds($serviceId, $requisitionId)) > 0;
    }

    public function assertRequiredDocumentsUploaded(Ticket $ticket): void
    {
        $required = $this->requiredDocumentTypeIds($ticket->service_id, $ticket->requisition_id);
        if ($required === []) {
            return;
        }

        $uploaded = $ticket->documents()->whereIn('document_type_id', $required)->pluck('document_type_id')->unique()->all();
        $missing = array_values(array_diff($required, $uploaded));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'documents' => 'Required documents are missing for this service request.',
                'missing_document_type_ids' => $missing,
            ]);
        }
    }

    public function createTicket(Client $client, array $data): Ticket
    {
        return DB::transaction(function () use ($client, $data) {
            $maxOpen = (int) config('vas.max_open_tickets', 1);
            $openCount = Ticket::query()
                ->where('client_id', $client->id)
                ->where('status', TicketStatus::Open)
                ->count();
            if ($openCount >= $maxOpen) {
                throw ValidationException::withMessages([
                    'ticket' => "You already have the maximum of {$maxOpen} open ticket(s).",
                ]);
            }

            $ticket = Ticket::query()->create([
                'tt_number' => $this->generateTtNumber(),
                'client_id' => $client->id,
                'service_id' => $data['service_id'],
                'requisition_id' => $data['requisition_id'],
                'category_id' => $data['category_id'],
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
                'actor_type' => Client::class,
                'actor_id' => $client->id,
                'note' => 'Ticket created',
                'created_at' => now(),
            ]);

            return $ticket->fresh();
        });
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

            return $ticket->fresh();
        });
    }

    public function decide(Ticket $ticket, User $approver, ApprovalAction $action, ?string $note = null): Ticket
    {
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
                // Push back to AM to fix docs
                $ticket->document_review_status = DocumentReviewStatus::Failed;
                $ticket->current_approver_user_id = null;
                $ticket->needs_reverification = true;
                $nextStatus = TicketStatus::InProgress;
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
                $this->transition($ticket, $nextStatus, $approver, $note);
            }

            return $ticket->fresh();
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

    protected function generateTtNumber(): string
    {
        return 'VAS-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -6));
    }
}
