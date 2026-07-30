<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketApprovalStep;
use App\Models\TicketAssignment;
use App\Models\TicketStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who + when audit trail for every ticket status milestone
 * (pending, assigned, in progress, approved/completed, closed, rejected).
 */
class TicketAuditTrailService
{
    /**
     * Persist missing history rows from denormalized stamps / related logs (legacy MVAS).
     */
    public function backfillMissingHistory(Ticket $ticket): int
    {
        $ticket->loadMissing(['contact', 'assignee', 'statusHistories', 'assignments.assignedBy', 'assignments.assignedTo', 'approvalSteps.approver']);

        $created = 0;

        DB::transaction(function () use ($ticket, &$created): void {
            if ($ticket->opened_at && ! $this->hasToStatus($ticket, TicketStatus::Open)) {
                $this->insertHistory(
                    $ticket,
                    null,
                    TicketStatus::Open,
                    $ticket->contact,
                    'Request submitted',
                    [
                        'event' => 'submitted',
                        'status_stamp_column' => 'opened_at',
                        'status_stamped_at' => $ticket->opened_at->toIso8601String(),
                        'backfilled' => true,
                    ],
                    $ticket->opened_at,
                );
                $created++;
            }

            $assignment = $ticket->assignments->sortBy('id')->last();
            $assignedAt = $ticket->assigned_at ?? $assignment?->created_at;
            if ($assignedAt && ! $this->hasEvent($ticket, 'assigned') && ! $this->hasEvent($ticket, 'reassigned')) {
                $assignee = $assignment?->assignedTo ?? $ticket->assignee;
                $assigner = $assignment?->assignedBy;
                $this->insertHistory(
                    $ticket,
                    TicketStatus::Open,
                    TicketStatus::InProgress,
                    $assigner ?: $assignee,
                    $assignee
                        ? 'Assigned to '.$assignee->name
                        : 'Assigned to account manager',
                    [
                        'event' => 'assigned',
                        'assignee_user_id' => $assignee?->id,
                        'assignee_name' => $assignee?->name,
                        'assigner_user_id' => $assigner?->id,
                        'assigner_name' => $assigner?->name,
                        'status_stamp_column' => 'assigned_at',
                        'status_stamped_at' => Carbon::parse($assignedAt)->toIso8601String(),
                        'backfilled' => true,
                    ],
                    Carbon::parse($assignedAt),
                );
                $created++;
            }

            if ($ticket->in_progress_at
                && ! $this->hasToStatus($ticket, TicketStatus::InProgress)
                && ! $this->hasEvent($ticket, 'assigned')) {
                $this->insertHistory(
                    $ticket,
                    TicketStatus::Open,
                    TicketStatus::InProgress,
                    $ticket->assignee,
                    'In progress',
                    [
                        'event' => 'in_progress',
                        'status_stamp_column' => 'in_progress_at',
                        'status_stamped_at' => $ticket->in_progress_at->toIso8601String(),
                        'backfilled' => true,
                    ],
                    $ticket->in_progress_at,
                );
                $created++;
            }

            if ($ticket->completed_at && ! $this->hasToStatus($ticket, TicketStatus::Completed)) {
                $step = $ticket->approvalSteps
                    ->filter(fn (TicketApprovalStep $s) => ($s->action instanceof ApprovalAction
                        ? $s->action
                        : ApprovalAction::tryFrom((string) $s->action)) === ApprovalAction::Approved)
                    ->sortByDesc('id')
                    ->first();
                $approver = $step?->approver;
                $this->insertHistory(
                    $ticket,
                    TicketStatus::InProgress,
                    TicketStatus::Completed,
                    $approver,
                    $step?->note ?: 'Request approved',
                    [
                        'event' => 'approved',
                        'approval_action' => ApprovalAction::Approved->value,
                        'approver_user_id' => $approver?->id,
                        'approver_name' => $approver?->name,
                        'status_stamp_column' => 'completed_at',
                        'status_stamped_at' => $ticket->completed_at->toIso8601String(),
                        'backfilled' => true,
                    ],
                    $ticket->completed_at,
                );
                $created++;
            }

            if ($ticket->closed_at && ! $this->hasToStatus($ticket, TicketStatus::Closed)) {
                $this->insertHistory(
                    $ticket,
                    TicketStatus::Completed,
                    TicketStatus::Closed,
                    $ticket->assignee,
                    'Request closed',
                    [
                        'event' => 'closed',
                        'status_stamp_column' => 'closed_at',
                        'status_stamped_at' => $ticket->closed_at->toIso8601String(),
                        'backfilled' => true,
                    ],
                    $ticket->closed_at,
                );
                $created++;
            }

            if ($ticket->rejected_at && ! $this->hasToStatus($ticket, TicketStatus::Rejected)) {
                $step = $ticket->approvalSteps
                    ->filter(fn (TicketApprovalStep $s) => ($s->action instanceof ApprovalAction
                        ? $s->action
                        : ApprovalAction::tryFrom((string) $s->action)) === ApprovalAction::Rejected)
                    ->sortByDesc('id')
                    ->first();
                $this->insertHistory(
                    $ticket,
                    TicketStatus::InProgress,
                    TicketStatus::Rejected,
                    $step?->approver,
                    $step?->note ?: 'Request rejected',
                    [
                        'event' => 'rejected',
                        'approval_action' => ApprovalAction::Rejected->value,
                        'approver_user_id' => $step?->approver_user_id,
                        'approver_name' => $step?->approver?->name,
                        'status_stamp_column' => 'rejected_at',
                        'status_stamped_at' => $ticket->rejected_at->toIso8601String(),
                        'backfilled' => true,
                    ],
                    $ticket->rejected_at,
                );
                $created++;
            }
        });

        if ($created > 0) {
            $ticket->unsetRelation('statusHistories');
        }

        return $created;
    }

    /**
     * Display-ready trail (after optional backfill).
     *
     * @return list<array{
     *   event: string,
     *   label: string,
     *   status: ?string,
     *   status_label: ?string,
     *   actor_name: ?string,
     *   detail: ?string,
     *   note: ?string,
     *   at: string,
     * }>
     */
    public function entries(Ticket $ticket, bool $backfill = true): array
    {
        if ($backfill) {
            $this->backfillMissingHistory($ticket);
        }

        $ticket->loadMissing(['statusHistories.actor', 'assignments.assignedBy', 'assignments.assignedTo']);

        return $ticket->statusHistories
            ->sortBy('created_at')
            ->values()
            ->map(fn (TicketStatusHistory $row) => $this->mapHistoryRow($row))
            ->all();
    }

    /**
     * Partner-safe trail (same facts; staff names allowed — already shown as assignee).
     *
     * @return list<array<string, mixed>>
     */
    public function forPortal(Ticket $ticket): array
    {
        return $this->entries($ticket, true);
    }

    protected function mapHistoryRow(TicketStatusHistory $record): array
    {
        $meta = is_array($record->meta) ? $record->meta : [];
        $to = TicketStatus::tryFrom((string) $record->to_status);
        $event = is_string($meta['event'] ?? null) && $meta['event'] !== ''
            ? (string) $meta['event']
            : $this->inferEvent($record, $meta);

        $actor = $record->actor;
        $actorName = $actor?->name
            ?? (is_string($meta['approver_name'] ?? null) ? (string) $meta['approver_name'] : null)
            ?? (is_string($meta['assigner_name'] ?? null) ? (string) $meta['assigner_name'] : null)
            ?? (in_array($event, ['assigned', 'reassigned'], true) && is_string($meta['assignee_name'] ?? null)
                ? (string) $meta['assignee_name']
                : null);

        if ($actorName === null || $actorName === '') {
            $actorName = $record->actor_type || $record->actor_id ? 'Staff' : 'System';
        }

        $detail = null;
        if (! empty($meta['assignee_name']) && ! in_array($event, ['assigned', 'reassigned'], true)) {
            $detail = 'Assigned to '.$meta['assignee_name'];
        } elseif (! empty($meta['assignee_name']) && in_array($event, ['assigned', 'reassigned'], true)
            && $actorName !== $meta['assignee_name']) {
            $detail = 'Assigned to '.$meta['assignee_name'];
        } elseif (! empty($meta['approver_name']) && $event === 'approved') {
            $detail = 'Approved by '.$meta['approver_name'];
        }

        return [
            'event' => $event,
            'label' => $this->eventLabel($event, $to),
            'status' => $to?->value,
            'status_label' => $to?->label(),
            'actor_name' => $actorName,
            'detail' => $detail,
            'note' => $record->note,
            'at' => $record->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    protected function inferEvent(TicketStatusHistory $record, array $meta): string
    {
        if (! empty($meta['approval_action'])) {
            return (string) $meta['approval_action'] === ApprovalAction::Approved->value
                ? 'approved'
                : 'rejected';
        }

        if (! empty($meta['reassignment'])) {
            return 'reassigned';
        }

        return match ($record->to_status) {
            TicketStatus::Open->value => ($record->from_status === null ? 'submitted' : 'pending'),
            TicketStatus::InProgress->value => 'in_progress',
            TicketStatus::Completed->value => 'approved',
            TicketStatus::Closed->value => 'closed',
            TicketStatus::Rejected->value => 'rejected',
            default => (string) $record->to_status,
        };
    }

    protected function eventLabel(string $event, ?TicketStatus $to): string
    {
        return match ($event) {
            'submitted' => 'Submitted',
            'pending' => 'Pending',
            'assigned' => 'Assigned',
            'reassigned' => 'Reassigned',
            'in_progress' => 'In progress',
            'approved' => 'Approved',
            'completed' => 'Completed',
            'closed' => 'Closed',
            'rejected' => 'Rejected',
            default => $to?->label() ?? ucfirst(str_replace('_', ' ', $event)),
        };
    }

    protected function hasToStatus(Ticket $ticket, TicketStatus $status): bool
    {
        return $ticket->statusHistories->contains(
            fn (TicketStatusHistory $h) => $h->to_status === $status->value
        );
    }

    protected function hasEvent(Ticket $ticket, string $event): bool
    {
        return $ticket->statusHistories->contains(function (TicketStatusHistory $h) use ($event) {
            $meta = is_array($h->meta) ? $h->meta : [];

            return ($meta['event'] ?? null) === $event;
        });
    }

    protected function insertHistory(
        Ticket $ticket,
        ?TicketStatus $from,
        TicketStatus $to,
        Contact|User|null $actor,
        ?string $note,
        array $meta,
        Carbon $at,
    ): void {
        TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->id,
            'note' => $note,
            'meta' => $meta,
            'created_at' => $at,
        ]);
    }
}
