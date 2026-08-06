<?php

namespace App\Services;

use App\Enums\RenewalInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription lifecycle driven by configurable requisition behaviors.
 *
 * Subscriptions belong to the company once the partner is linked.
 * - creates_subscription (new) → Active on ticket completed/closed
 * - renews_subscription (renew) → extend period on completed/closed
 * - terminates_subscription (terminate) → Deactive on completed/closed (partner consent via request)
 * Renewal cadence (yearly / bi-yearly) is configured per service.
 *
 * Staff may also Close a subscription after contract expiration follow-up
 * (contract signing date + renewal year; premium also needs VAS license expiry).
 *
 * Request status "closed" is never a subscription status.
 */
class SubscriptionLifecycleService
{
    public function __construct(
        protected CompanyMembershipService $membership,
    ) {}

    public function assertTicketAllowed(Contact $contact, array $data, Requisition $requisition, Service $service): void
    {
        $this->membership->assertCanAccessCompany($contact);
        $companyId = (int) $contact->company_id;

        if ($requisition->requires_active_subscription || $requisition->renews_subscription || $requisition->terminates_subscription) {
            // Non-subscription services are managed without an alive subscription.
            if ($service->is_subscription_based) {
                $subscription = $this->resolveSubscription($data['subscription_id'] ?? null, $companyId, $service->id);
                if (! $subscription || ! $subscription->status->isAlive()) {
                    throw ValidationException::withMessages([
                        'subscription_id' => 'An active company subscription is required for this request type.',
                    ]);
                }
            }
        }

        // One active request per company + service + request type until it is closed (or rejected).
        $pending = Ticket::query()
            ->where('service_id', $service->id)
            ->where('requisition_id', $requisition->id)
            ->whereIn('status', [
                TicketStatus::Open->value,
                TicketStatus::InProgress->value,
            ])
            ->whereHas('contact.memberships', fn ($q) => $q->where('company_id', $companyId))
            ->latest('id')
            ->first(['id', 'tt_number', 'public_id', 'status']);

        if ($pending) {
            throw ValidationException::withMessages([
                'service_id' => sprintf(
                    'Your company already has an open %s request for %s (request number %s). Wait until it is closed before submitting another.',
                    $requisition->name ?: 'service',
                    $service->name ?: 'this service',
                    $pending->tt_number,
                ),
                'duplicate_ticket_public_id' => $pending->public_id,
            ]);
        }

        if ($requisition->creates_subscription && $service->is_subscription_based) {
            if ($this->companyHasAliveSubscription($companyId, $service->id)) {
                throw ValidationException::withMessages([
                    'service_id' => 'Your company already has an active subscription for this service. Use manage / renew / terminate instead of starting another.',
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
        $ticket->loadMissing(['requisition', 'service', 'subscription', 'contact']);
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

            $ticket->loadMissing('contact');
            $companyId = (int) ($ticket->contact?->company_id ?? 0);
            if ($companyId < 1) {
                throw ValidationException::withMessages([
                    'company' => 'Cannot activate a subscription without a company.',
                ]);
            }

            // Serialize activation per company + service to prevent double subscriptions.
            Subscription::query()
                ->where('company_id', $companyId)
                ->where('service_id', $ticket->service_id)
                ->lockForUpdate()
                ->get();

            $existing = $this->aliveSubscriptionFor($companyId, $ticket->service_id);
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
                'contact_id' => $ticket->contact_id,
                'company_id' => $companyId,
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

            app(SubscriptionProvisioningLogService::class)->record(
                $subscription,
                'activated',
                $ticket->contact,
                $ticket,
                null,
                SubscriptionStatus::Active->value,
                'Subscription activated from request '.$ticket->tt_number,
            );

            return $subscription;
        });
    }

    public function renewFromTicket(Ticket $ticket): Subscription
    {
        return DB::transaction(function () use ($ticket) {
            /** @var Subscription $subscription */
            $subscription = $ticket->subscription()->lockForUpdate()->firstOrFail();

            if (in_array($subscription->status, [SubscriptionStatus::Deactive, SubscriptionStatus::Closed], true)) {
                throw ValidationException::withMessages([
                    'subscription' => 'Cannot renew a '.$subscription->status->label().' subscription.',
                ]);
            }

            $from = SubscriptionProvisioningLogService::statusValue($subscription->status);
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

            app(SubscriptionProvisioningLogService::class)->record(
                $subscription,
                'renewed',
                $ticket->contact,
                $ticket,
                $from,
                SubscriptionStatus::Active->value,
                'Subscription renewed from request '.$ticket->tt_number,
                [
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                ],
            );

            return $subscription->fresh();
        });
    }

    public function terminateFromTicket(Ticket $ticket): Subscription
    {
        return DB::transaction(function () use ($ticket) {
            /** @var Subscription $subscription */
            $subscription = $ticket->subscription()->lockForUpdate()->firstOrFail();
            $from = SubscriptionProvisioningLogService::statusValue($subscription->status);

            $subscription->fill([
                'status' => SubscriptionStatus::Deactive,
                'terminated_at' => now(),
                'terminated_by_ticket_id' => $ticket->id,
                'next_renewal_due_at' => null,
            ])->save();

            app(SubscriptionProvisioningLogService::class)->record(
                $subscription,
                'terminated',
                $ticket->contact,
                $ticket,
                $from,
                SubscriptionStatus::Deactive->value,
                'Subscription deactivated from request '.$ticket->tt_number,
            );

            return $subscription->fresh();
        });
    }

    /**
     * Record contract follow-up fields (signing date, renewal date; premium also VAS license expiry).
     *
     * @param  array{
     *   contract_signed_at?: mixed,
     *   renewal_years?: mixed,
     *   renewal_date?: mixed,
     *   automatic_renewal?: mixed,
     *   vas_license_expires_at?: mixed
     * }  $data
     */
    public function updateContractDetails(Subscription $subscription, array $data, ?Model $actor = null): Subscription
    {
        $subscription->loadMissing('service', 'company');

        $payload = $this->validatedContractPayload($subscription, $data, requireComplete: true);

        return DB::transaction(function () use ($subscription, $payload, $actor) {
            /** @var Subscription $locked */
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $locked->fill($payload)->save();

            if (
                array_key_exists('vas_license_expires_at', $payload)
                && $payload['vas_license_expires_at'] !== null
                && $locked->company_id
            ) {
                $locked->company?->forceFill([
                    'license_valid_until' => $payload['vas_license_expires_at'],
                ])->save();
            }

            app(SubscriptionProvisioningLogService::class)->record(
                $locked,
                'contract_details_updated',
                $actor,
                null,
                null,
                null,
                'Contract follow-up details recorded',
                $payload,
            );

            return $locked->fresh(['service', 'company']);
        });
    }

    /**
     * Close an alive subscription after contract expiration follow-up.
     * Requires contract signing date + renewal date; premium also requires VAS license expiry.
     *
     * @param  array{
     *   contract_signed_at?: mixed,
     *   renewal_years?: mixed,
     *   renewal_date?: mixed,
     *   automatic_renewal?: mixed,
     *   vas_license_expires_at?: mixed,
     *   note?: ?string
     * }  $data
     */
    public function closeForContractFollowUp(Subscription $subscription, array $data = [], ?Model $actor = null): Subscription
    {
        $subscription->loadMissing('service', 'company');

        if ($subscription->status === SubscriptionStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'This subscription is already closed.',
            ]);
        }

        if (! $subscription->status->isAlive() && $subscription->status !== SubscriptionStatus::Expired) {
            throw ValidationException::withMessages([
                'status' => 'Only active, pending renewal, grace, or expired subscriptions can be closed for contract follow-up.',
            ]);
        }

        return DB::transaction(function () use ($subscription, $data, $actor) {
            /** @var Subscription $locked */
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('service', 'company');

            $merged = [
                'contract_signed_at' => $data['contract_signed_at'] ?? $locked->contract_signed_at,
                'renewal_years' => $data['renewal_years'] ?? $locked->renewal_years,
                'renewal_date' => $data['renewal_date'] ?? $locked->renewal_date,
                'automatic_renewal' => array_key_exists('automatic_renewal', $data)
                    ? (bool) $data['automatic_renewal']
                    : (bool) $locked->automatic_renewal,
                'vas_license_expires_at' => $data['vas_license_expires_at'] ?? $locked->vas_license_expires_at,
            ];

            $payload = $this->validatedContractPayload($locked, $merged, requireComplete: true);
            $locked->fill($payload)->save();
            $locked->refresh();

            if (
                $locked->vas_license_expires_at !== null
                && $locked->company_id
            ) {
                $locked->company?->forceFill([
                    'license_valid_until' => $locked->vas_license_expires_at,
                ])->save();
            }

            $from = SubscriptionProvisioningLogService::statusValue($locked->status);

            $locked->fill([
                'status' => SubscriptionStatus::Closed,
                'closed_at' => now(),
                'next_renewal_due_at' => null,
            ])->save();

            app(SubscriptionProvisioningLogService::class)->record(
                $locked,
                'closed',
                $actor,
                null,
                $from,
                SubscriptionStatus::Closed->value,
                filled($data['note'] ?? null)
                    ? trim((string) $data['note'])
                    : 'Subscription closed after contract expiration follow-up',
                [
                    'contract_signed_at' => optional($locked->contract_signed_at)?->toDateString(),
                    'renewal_years' => $locked->renewal_years,
                    'renewal_date' => optional($locked->renewal_date)?->toDateString(),
                    'automatic_renewal' => (bool) $locked->automatic_renewal,
                    'vas_license_expires_at' => optional($locked->vas_license_expires_at)?->toDateString(),
                ],
            );

            return $locked->fresh(['service', 'company']);
        });
    }

    /**
     * Validate and normalize contract follow-up fields.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *   contract_signed_at?: string,
     *   renewal_years?: int,
     *   renewal_date?: string,
     *   automatic_renewal?: bool,
     *   vas_license_expires_at?: string|null
     * }
     */
    protected function validatedContractPayload(Subscription $subscription, array $data, bool $requireComplete = true): array
    {
        $errors = [];
        $payload = [];
        $premium = $subscription->requiresVasLicenseExpiry();

        $signedRaw = $data['contract_signed_at'] ?? null;
        if ($requireComplete || array_key_exists('contract_signed_at', $data)) {
            if (! filled($signedRaw)) {
                if ($requireComplete) {
                    $errors['contract_signed_at'] = 'Contract signing date is required.';
                }
            } else {
                try {
                    $signed = \Illuminate\Support\Carbon::parse($signedRaw)->startOfDay();
                } catch (\Throwable) {
                    $errors['contract_signed_at'] = 'Enter a valid contract signing date.';
                    $signed = null;
                }

                if ($signed !== null) {
                    if ($signed->greaterThan(now()->startOfDay())) {
                        $errors['contract_signed_at'] = 'Contract signing date cannot be in the future.';
                    } else {
                        $payload['contract_signed_at'] = $signed->toDateString();
                    }
                }
            }
        }

        $yearsRaw = $data['renewal_years'] ?? null;
        if ($requireComplete || array_key_exists('renewal_years', $data)) {
            if (! filled($yearsRaw) && $yearsRaw !== 0 && $yearsRaw !== '0') {
                if ($requireComplete) {
                    $errors['renewal_years'] = 'Renewal year is required.';
                }
            } else {
                $years = (int) $yearsRaw;
                if ($years < 1 || $years > 10) {
                    $errors['renewal_years'] = 'Renewal year must be between 1 and 10.';
                } else {
                    $payload['renewal_years'] = $years;
                }
            }
        }

        $renewalRaw = $data['renewal_date'] ?? null;
        if (
            ! filled($renewalRaw)
            && isset($payload['renewal_years'])
        ) {
            $composed = Subscription::composeRenewalDate(
                $payload['contract_signed_at'] ?? $data['contract_signed_at'] ?? $subscription->contract_signed_at,
                $payload['renewal_years'],
            );
            $renewalRaw = $composed?->toDateString();
        }

        if ($requireComplete || array_key_exists('renewal_date', $data) || array_key_exists('renewal_years', $data)) {
            if (! filled($renewalRaw)) {
                if ($requireComplete) {
                    $errors['renewal_date'] = 'Renewal date is required.';
                }
            } else {
                try {
                    $renewal = \Illuminate\Support\Carbon::parse($renewalRaw)->startOfDay();
                    $payload['renewal_date'] = $renewal->toDateString();
                } catch (\Throwable) {
                    $errors['renewal_date'] = 'Enter a valid renewal date.';
                }
            }
        }

        if (array_key_exists('automatic_renewal', $data) || $requireComplete) {
            $payload['automatic_renewal'] = (bool) ($data['automatic_renewal'] ?? false);
        }

        $licenseRaw = $data['vas_license_expires_at'] ?? null;
        if ($premium) {
            if ($requireComplete || array_key_exists('vas_license_expires_at', $data)) {
                if (! filled($licenseRaw)) {
                    if ($requireComplete) {
                        $errors['vas_license_expires_at'] = 'VAS license expiry date is required.';
                    }
                } else {
                    try {
                        $license = \Illuminate\Support\Carbon::parse($licenseRaw)->startOfDay();
                        $payload['vas_license_expires_at'] = $license->toDateString();
                    } catch (\Throwable) {
                        $errors['vas_license_expires_at'] = 'Enter a valid VAS license expiry date.';
                    }
                }
            }
        } elseif (array_key_exists('vas_license_expires_at', $data) && filled($licenseRaw)) {
            try {
                $payload['vas_license_expires_at'] = \Illuminate\Support\Carbon::parse($licenseRaw)->toDateString();
            } catch (\Throwable) {
                $errors['vas_license_expires_at'] = 'Enter a valid VAS license expiry date.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $payload;
    }

    /**
     * Create open renewal tickets for subscriptions entering the lead window.
     */
    public function openDueRenewalTickets(TicketWorkflowService $workflow): int
    {
        $created = 0;

        Subscription::query()
            ->with(['service.renewalRequisition', 'contact', 'company'])
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

                    $actor = $subscription->company?->ownerContact()
                        ?? $subscription->contact;
                    if (! $actor) {
                        continue;
                    }

                    $renewalTicket = $workflow->createTicket($actor, [
                        'service_id' => $subscription->service_id,
                        'requisition_id' => $requisition->id,
                        'category_id' => $subscription->service->category_id,
                        'subscription_id' => $subscription->id,
                        'description' => 'Automatic renewal request for period ending '.$subscription->current_period_end->toDateString(),
                        'skip_open_limit' => true,
                    ]);

                    $from = SubscriptionProvisioningLogService::statusValue($subscription->status);
                    $subscription->status = SubscriptionStatus::PendingRenewal;
                    $subscription->save();

                    app(SubscriptionProvisioningLogService::class)->record(
                        $subscription,
                        'pending_renewal',
                        $actor,
                        $renewalTicket,
                        $from,
                        SubscriptionStatus::PendingRenewal->value,
                        'Automatic renewal window opened',
                    );
                    $created++;
                }
            });

        return $created;
    }

    protected function resolveSubscription(?int $subscriptionId, int $companyId, int $serviceId): ?Subscription
    {
        if ($subscriptionId) {
            return Subscription::query()
                ->where('id', $subscriptionId)
                ->where('company_id', $companyId)
                ->where('service_id', $serviceId)
                ->first();
        }

        return $this->aliveSubscriptionFor($companyId, $serviceId);
    }

    public function companyHasAliveSubscription(int $companyId, int $serviceId): bool
    {
        return $this->aliveSubscriptionFor($companyId, $serviceId) !== null;
    }

    /** @deprecated Use companyHasAliveSubscription */
    public function contactHasAliveSubscription(int $contactId, int $serviceId): bool
    {
        $companyId = (int) Contact::query()->where('id', $contactId)->value('company_id');
        if ($companyId < 1) {
            return false;
        }

        return $this->companyHasAliveSubscription($companyId, $serviceId);
    }

    protected function aliveSubscriptionFor(int $companyId, int $serviceId): ?Subscription
    {
        return Subscription::query()
            ->where('company_id', $companyId)
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
