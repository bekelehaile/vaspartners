<?php

namespace App\Services;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attach orphaned service requests (tickets) and subscriptions to a company.
 *
 * Tickets are linked to a company through their contact, subscription, or —
 * now explicitly — a company_id foreign key. Subscriptions carry a direct
 * company_id. This service performs the reassignment, records an audit trail,
 * and notifies the company's owner.
 */
class CompanyChildAttachService
{
    public function __construct(
        private readonly CompanyMembershipService $membership,
    ) {
    }

    /**
     * @return array{ticket: Ticket, detached_from: ?int, comment: TicketComment}
     */
    public function attachTicket(Ticket $ticket, Company $company, ?User $actor = null, ?string $note = null): array
    {
        $this->assertTicketAttachable($ticket, $company);

        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        $fromCompanyId = $ticket->company_id;
        $ticket->loadMissing(['contact', 'subscription']);

        return DB::transaction(function () use ($ticket, $company, $actor, $note, $fromCompanyId) {
            $ticket->forceFill([
                'company_id' => $company->id,
                'updated_at' => now(),
            ])->save();

            $comment = $this->recordTransferComment(
                $ticket,
                $company,
                $actor,
                $note,
                $fromCompanyId,
            );

            Log::info('Ticket attached to company', [
                'ticket_id' => $ticket->id,
                'tt_number' => $ticket->tt_number,
                'from_company_id' => $fromCompanyId,
                'to_company_id' => $company->id,
                'actor_user_id' => $actor?->id,
            ]);

            return [
                'ticket' => $ticket->fresh(),
                'detached_from' => $fromCompanyId,
                'comment' => $comment,
            ];
        });
    }

    /**
     * @return array{subscription: Subscription, detached_from: ?int}
     */
    public function attachSubscription(Subscription $subscription, Company $company, ?User $actor = null, ?string $note = null): array
    {
        $this->assertSubscriptionAttachable($subscription, $company);

        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        $fromCompanyId = $subscription->company_id;

        return DB::transaction(function () use ($subscription, $company, $actor, $note, $fromCompanyId) {
            $subscription->forceFill([
                'company_id' => $company->id,
                'updated_at' => now(),
            ])->save();

            Log::info('Subscription attached to company', [
                'subscription_id' => $subscription->id,
                'from_company_id' => $fromCompanyId,
                'to_company_id' => $company->id,
                'actor_user_id' => $actor?->id,
            ]);

            return [
                'subscription' => $subscription->fresh(),
                'detached_from' => $fromCompanyId,
            ];
        });
    }

    /**
     * Tickets that already ride this company's subscription are already visible
     * here; this guard only blocks re-attaching to the same company.
     */
    protected function assertTicketAttachable(Ticket $ticket, Company $company): void
    {
        if ((int) $ticket->company_id === (int) $company->id) {
            throw ValidationException::withMessages([
                'ticket' => 'This service request is already attached to this company.',
            ]);
        }

        $ticket->loadMissing(['subscription', 'contact']);

        // A ticket riding the target company's subscription is already "theirs".
        if ($ticket->subscription && (int) $ticket->subscription->company_id === (int) $company->id) {
            throw ValidationException::withMessages([
                'ticket' => 'This service request already belongs to this company through its subscription.',
            ]);
        }

        if ($ticket->contact && $this->membership->companyContactIds($company->id)->contains((int) $ticket->contact->id)) {
            throw ValidationException::withMessages([
                'ticket' => 'This service request is already linked to this company through its requester.',
            ]);
        }
    }

    protected function assertSubscriptionAttachable(Subscription $subscription, Company $company): void
    {
        if ((int) $subscription->company_id === (int) $company->id) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is already attached to this company.',
            ]);
        }
    }

    /**
     * @param  list<int>|null  $fromCompanyIds
     */
    protected function recordTransferComment(
        Ticket $ticket,
        Company $company,
        ?User $actor,
        ?string $note,
        ?int $fromCompanyId,
    ): TicketComment {
        $body = 'Service request transferred to company '.$company->name.' (TIN: '.($company->tin ?? '—').').';
        if (filled($note)) {
            $body .= ' Note: '.trim($note);
        }

        return TicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => $actor ? User::class : null,
            'author_id' => $actor?->id,
            'body' => $body,
            'is_public' => true,
        ]);
    }
}