<?php

namespace App\Services;

use App\Enums\ServiceOperationalStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionProvisioningLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SubscriptionProvisioningLogService
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        Subscription $subscription,
        string $event,
        ?Model $actor = null,
        ?Ticket $ticket = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
        ?array $meta = null,
    ): SubscriptionProvisioningLog {
        return SubscriptionProvisioningLog::query()->create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'ticket_id' => $ticket?->id,
            'note' => filled($note) ? trim($note) : null,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    public function setOperationalStatus(
        Subscription $subscription,
        ServiceOperationalStatus $status,
        User $actor,
        ?string $note = null,
    ): Subscription {
        $from = $subscription->operational_status instanceof ServiceOperationalStatus
            ? $subscription->operational_status
            : ServiceOperationalStatus::tryFrom((string) ($subscription->operational_status ?? ''))
                ?? ServiceOperationalStatus::Unknown;

        if ($from === $status) {
            return $subscription;
        }

        $subscription->forceFill([
            'operational_status' => $status,
            'operational_status_updated_at' => now(),
        ])->save();

        $this->record(
            $subscription,
            'operational_status_changed',
            $actor,
            null,
            $from->value,
            $status->value,
            $note ?? ('Uptime status set to '.$status->label()),
            [
                'from_label' => $from->label(),
                'to_label' => $status->label(),
            ],
        );

        return $subscription->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPortal(Subscription $subscription, int $limit = 50): array
    {
        return $subscription->provisioningLogs()
            ->with(['ticket:id,tt_number,public_id', 'actor'])
            ->limit($limit)
            ->get()
            ->map(function (SubscriptionProvisioningLog $log): array {
                $actorName = null;
                $actor = $log->actor;
                if ($actor instanceof User) {
                    $actorName = $actor->name ?: ($actor->email ?? 'Staff');
                } elseif ($actor instanceof \App\Models\Contact) {
                    $actorName = $actor->name ?: 'Partner';
                }

                return [
                    'event' => $log->event,
                    'label' => $log->eventLabel(),
                    'from_status' => $log->from_status,
                    'to_status' => $log->to_status,
                    'note' => $log->note,
                    'actor' => $actorName,
                    'ticket' => $log->ticket
                        ? [
                            'tt_number' => $log->ticket->tt_number,
                            'public_id' => $log->ticket->public_id,
                        ]
                        : null,
                    'created_at' => optional($log->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    public static function statusValue(SubscriptionStatus|string|null $status): ?string
    {
        if ($status instanceof SubscriptionStatus) {
            return $status->value;
        }

        return filled($status) ? (string) $status : null;
    }
}
