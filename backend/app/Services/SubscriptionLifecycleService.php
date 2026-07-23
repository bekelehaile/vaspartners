<?php

namespace App\Services;

use App\Enums\RenewalInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription lifecycle driven by configurable requisition behaviors.
 *
 * - creates_subscription (new) → activate on ticket completed/closed
 * - renews_subscription (renew) → extend period on completed/closed
 * - terminates_subscription (terminate) → end subscription on completed/closed
 * Renewal cadence (yearly / bi-yearly) is configured per service.
 */
class SubscriptionLifecycleService
{
    public function assertTicketAllowed(int $customerId, array $data, Requisition $requisition, Service $service): void
    {
        if ($requisition->requires_active_subscription || $requisition->renews_subscription || $requisition->terminates_subscription) {
            // Non-subscription services are managed without an alive subscription.
            if ($service->is_subscription_based) {
                $subscription = $this->resolveSubscription($data['subscription_id'] ?? null, $customerId, $service->id);
                if (! $subscription || ! $subscription->status->isAlive()) {
                    throw ValidationException::withMessages([
                        'subscription_id' => 'An active subscription is required for this request type.',
                    ]);
                }
            }
        }

        if ($requisition->creates_subscription && $service->is_subscription_based) {
            if ($this->customerHasAliveSubscription($customerId, $service->id)) {
                throw ValidationException::withMessages([
                    'service_id' => 'You already have an active subscription for this service. Use manage / renew / terminate instead of starting another.',
                ]);
            }

            $pendingNew = Ticket::query()
                ->where('customer_id', $customerId)
                ->where('service_id', $service->id)
                ->where('requisition_id', $requisition->id)
                ->whereIn('status', [
                    TicketStatus::Open->value,
                    TicketStatus::InProgress->value,
                ])
                ->exists();

            if ($pendingNew) {
                throw ValidationException::withMessages([
                    'service_id' => 'You already have a new-subscription request in progress for this service.',
                ]);
            }

            if (! $service->renewal_interval) {
                throw ValidationException::withMessages([
                    'service_id' => 'This service has no renewal interval configured (yearly / bi-yearly).',
                ]);
            }
        }
    }

    public function applyFromTicket(Ticket $ticket): void
    {
        $ticket->loadMissing(['requisition', 'service', 'subscription']);
        $requisition = $ticket->requisition;
        $service = $ticket->service;

        if (! $requisition || ! $service) {
            return;
        }

        if ($requisition->creates_subscription && $service->is_subscription_based) {
            $this->activateFromNewTicket($ticket);
        }

        if ($requisition->renews_subscription && $ticket->subscription_id) {
            $this->renewFromTicket($ticket);
        }

        if ($requisition->terminates_subscription && $ticket->subscription_id) {
            $this->terminateFromTicket($ticket);
        }
    }

    public function activateFromNewTicket(Ticket $ticket): Subscription
    {
        return DB::transaction(function () use ($ticket) {
            if ($ticket->subscription_id) {
                return $ticket->subscription()->firstOrFail();
            }

            // Serialize activation per Fayda customer + service to prevent double subscriptions.
            Subscription::query()
                ->where('customer_id', $ticket->customer_id)
                ->where('service_id', $ticket->service_id)
                ->lockForUpdate()
                ->get();

            $existing = $this->aliveSubscriptionFor($ticket->customer_id, $ticket->service_id);
            if ($existing) {
                $ticket->subscription_id = $existing->id;
                $ticket->save();

                return $existing;
            }

            $interval = $ticket->service->renewal_interval
                ?? RenewalInterval::from(config('vas.default_renewal_interval', 'yearly'));

            $start = now();
            $end = $start->copy()->addMonthsNoOverflow($interval->months());

            $subscription = Subscription::query()->create([
                'customer_id' => $ticket->customer_id,
                'service_id' => $ticket->service_id,
                'status' => SubscriptionStatus::Active,
                'renewal_interval' => $interval,
                'started_at' => $start,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'next_renewal_due_at' => $end->copy()->subDays((int) $ticket->service->renewal_lead_days),
                'activated_by_ticket_id' => $ticket->id,
            ]);

            $ticket->subscription_id = $subscription->id;
            $ticket->save();

            return $subscription;
        });
    }

    public function renewFromTicket(Ticket $ticket): Subscription
    {
        return DB::transaction(function () use ($ticket) {
            /** @var Subscription $subscription */
            $subscription = $ticket->subscription()->lockForUpdate()->firstOrFail();

            if ($subscription->status === SubscriptionStatus::Terminated) {
                throw ValidationException::withMessages([
                    'subscription' => 'Cannot renew a terminated subscription.',
                ]);
            }

            $interval = $subscription->renewal_interval;
            $periodStart = $subscription->current_period_end->greaterThan(now())
                ? $subscription->current_period_end->copy()
                : now();
            $periodEnd = $periodStart->copy()->addMonthsNoOverflow($interval->months());

            $subscription->fill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'next_renewal_due_at' => $periodEnd->copy()->subDays((int) $ticket->service->renewal_lead_days),
            ])->save();

            return $subscription->fresh();
        });
    }

    public function terminateFromTicket(Ticket $ticket): Subscription
    {
        return DB::transaction(function () use ($ticket) {
            /** @var Subscription $subscription */
            $subscription = $ticket->subscription()->lockForUpdate()->firstOrFail();

            $subscription->fill([
                'status' => SubscriptionStatus::Terminated,
                'terminated_at' => now(),
                'terminated_by_ticket_id' => $ticket->id,
                'next_renewal_due_at' => null,
            ])->save();

            return $subscription->fresh();
        });
    }

    /**
     * Create open renewal tickets for subscriptions entering the lead window.
     */
    public function openDueRenewalTickets(TicketWorkflowService $workflow): int
    {
        $created = 0;

        Subscription::query()
            ->with(['service.renewalRequisition', 'customer'])
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PendingRenewal->value])
            ->whereNotNull('next_renewal_due_at')
            ->where('next_renewal_due_at', '<=', now())
            ->where('current_period_end', '>', now())
            ->orderBy('id')
            ->chunkById(50, function ($subscriptions) use ($workflow, &$created) {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->service?->is_subscription_based) {
                        continue;
                    }

                    $requisition = $subscription->service->renewalRequisition
                        ?? Requisition::query()->where('code', 'renew')->where('is_active', true)->first();

                    if (! $requisition) {
                        continue;
                    }

                    $alreadyOpen = Ticket::query()
                        ->where('subscription_id', $subscription->id)
                        ->where('requisition_id', $requisition->id)
                        ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
                        ->exists();

                    if ($alreadyOpen) {
                        continue;
                    }

                    $workflow->createTicket($subscription->customer, [
                        'service_id' => $subscription->service_id,
                        'requisition_id' => $requisition->id,
                        'category_id' => $subscription->service->category_id,
                        'subscription_id' => $subscription->id,
                        'description' => 'Automatic renewal request for period ending '.$subscription->current_period_end->toDateString(),
                        'skip_open_limit' => true,
                    ]);

                    $subscription->status = SubscriptionStatus::PendingRenewal;
                    $subscription->save();
                    $created++;
                }
            });

        return $created;
    }

    protected function resolveSubscription(?int $subscriptionId, ?int $customerId, int $serviceId): ?Subscription
    {
        if ($subscriptionId) {
            return Subscription::query()
                ->where('id', $subscriptionId)
                ->where('customer_id', $customerId)
                ->where('service_id', $serviceId)
                ->first();
        }

        return $this->aliveSubscriptionFor((int) $customerId, $serviceId);
    }

    public function customerHasAliveSubscription(int $customerId, int $serviceId): bool
    {
        return $this->aliveSubscriptionFor($customerId, $serviceId) !== null;
    }

    protected function aliveSubscriptionFor(int $customerId, int $serviceId): ?Subscription
    {
        return Subscription::query()
            ->where('customer_id', $customerId)
            ->where('service_id', $serviceId)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PendingRenewal->value,
                SubscriptionStatus::Grace->value,
            ])
            ->orderBy('id')
            ->first();
    }
}
