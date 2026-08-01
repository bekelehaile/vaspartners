<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Contact;
use App\Models\Company;
use App\Models\CompanyChangeRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\PartnerPortalNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Partner + staff communications: Ethio telecom SMS and in-app notifications.
 */
class PartnerNotificationService
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    public function ticketSubmitted(Ticket $ticket): void
    {
        $this->notifyPartner($ticket, 'ticket_submitted');
        $this->notifyStaffDatabase(
            $this->managementUsers(),
            'New VAS request',
            sprintf(
                '%s submitted request number %s for %s.',
                $ticket->contact?->company_name ?: $ticket->contact?->name ?: 'A partner',
                $ticket->tt_number,
                $ticket->service?->name ?: 'a service',
            ),
            $ticket,
        );
    }

    /** Partner fixed a rejected request — route back to the AM handler when known. */
    public function ticketResubmitted(Ticket $ticket): void
    {
        $ticket->loadMissing(['contact', 'service', 'assignee']);
        $handler = $this->activeAccountManager($ticket->assignee);
        $statusLine = $handler
            ? sprintf('Returned to account manager %s (In progress).', $handler->name)
            : 'Status is Pending (unassigned).';
        $body = sprintf(
            '%s updated and resubmitted request number %s for %s. %s',
            $ticket->contact?->name ?: 'A partner',
            $ticket->tt_number,
            $ticket->service?->name ?: 'a service',
            $statusLine,
        );

        $recipients = $this->managementUsers();
        if ($handler) {
            $recipients = $recipients->push($handler)->unique('id');
        }

        $this->notifyStaffDatabase(
            $recipients,
            'Request resubmitted',
            $body,
            $ticket,
        );
    }

    public function ticketStatusChanged(Ticket $ticket, ?TicketStatus $from, TicketStatus $to, ?string $note = null): void
    {
        $template = match (true) {
            $to === TicketStatus::InProgress && $from === TicketStatus::Open => 'ticket_in_progress',
            $to === TicketStatus::Completed => 'ticket_completed',
            $to === TicketStatus::Rejected => 'ticket_rejected',
            $to === TicketStatus::Closed => 'ticket_closed',
            default => null,
        };

        if ($template) {
            $this->notifyPartner($ticket, $template, $note);
        }

        if ($to === TicketStatus::InProgress && $from === TicketStatus::Open && $ticket->assigned_to_user_id) {
            $assignee = $this->activeAccountManager(User::query()->find($ticket->assigned_to_user_id));
            if ($assignee) {
                $this->notifyStaffDatabase(
                    collect([$assignee]),
                    'Ticket assigned to you',
                    sprintf('Request number %s (%s) is ready for your review.', $ticket->tt_number, $ticket->service?->name ?: 'VAS'),
                    $ticket,
                );
            }
        }
    }

    public function documentsNeedAttention(Ticket $ticket, ?string $note = null): void
    {
        $this->notifyPartner($ticket, 'documents_need_attention', $note);
    }

    /** Automated scan: returned for missing required documents (option B wording). */
    public function documentsIncompleteAutoRejected(Ticket $ticket): void
    {
        $this->notifyPartner($ticket, 'documents_incomplete_auto');
    }

    public function documentsPassed(Ticket $ticket, ?string $note = null): void
    {
        $this->notifyPartner($ticket, 'documents_passed', $note);
    }

    public function approvalNeeded(Ticket $ticket, User $approver): void
    {
        $this->notifyStaffDatabase(
            collect([$approver]),
            'Approval required',
            sprintf('Request number %s needs your decision (%s).', $ticket->tt_number, $ticket->service?->name ?: 'VAS'),
            $ticket,
        );
    }

    /** Notify the other party when a public chat message is posted. */
    public function ticketMessagePosted(Ticket $ticket, Contact|User $author, TicketComment $comment): void
    {
        $ticket->loadMissing(['contact', 'service', 'assignee', 'currentApprover']);
        $preview = Str::limit(trim((string) $comment->body), 120);
        if ($comment->hasAttachment()) {
            $preview = trim($preview.' [PDF: '.($comment->attachment_original_name ?: 'attachment').']');
        }

        // Partner → account manager (admin in-app). Always notify so AMs see portal replies.
        if ($author instanceof Contact) {
            $recipients = collect();
            $assignee = $this->activeAccountManager($ticket->assignee);
            if ($assignee) {
                $recipients->push($assignee);
            }
            if ($ticket->currentApprover
                && $ticket->currentApprover->is_active
                && (! $assignee || (int) $ticket->currentApprover->id !== (int) $assignee->id)) {
                $recipients->push($ticket->currentApprover);
            }
            if ($recipients->isEmpty()) {
                // Unassigned: alert active Account Managers who can take this group's tickets (+ supervisors).
                $categoryId = (int) ($ticket->category_id ?: $ticket->service?->category_id ?: 0);
                $managerIds = User::assignableManagersForCategory($categoryId > 0 ? $categoryId : null)->keys();
                if ($managerIds->isNotEmpty()) {
                    $recipients = User::query()
                        ->whereIn('id', $managerIds)
                        ->where('is_active', true)
                        ->get();
                }
                $recipients = $recipients->merge($this->managementUsers());
            }

            $this->notifyStaffDatabase(
                $recipients->unique('id')->filter(fn ($u) => $u instanceof User && $u->is_active),
                'Partner message',
                sprintf(
                    '%s messaged on request %s (%s): %s',
                    $author->name ?: 'Partner',
                    $ticket->tt_number,
                    $ticket->service?->name ?: 'VAS',
                    $preview !== '' ? $preview : 'Sent an attachment',
                ),
                $ticket,
                icon: 'heroicon-o-chat-bubble-left-right',
            );

            return;
        }

        // Staff → partner (portal + SMS — debounced to avoid spam)
        if (! $this->shouldNotifyForChatMessage($ticket, $author, $comment)) {
            return;
        }

        $ticket->loadMissing(['contact', 'service', 'requisition']);
        $contact = $ticket->contact;
        if (! $contact) {
            return;
        }

        $placeholders = [
            'contact_name' => $contact->name ?: 'Partner',
            'company_name' => $contact->company_name ?: 'your organisation',
            'tt_number' => $ticket->tt_number,
            'service' => $ticket->service?->name ?: 'VAS service',
            'requisition' => $ticket->requisition?->name ?: 'request',
            'status' => $ticket->status?->label() ?: (string) $ticket->status?->value,
            'note' => $preview !== '' ? $preview : 'Sent an attachment',
        ];
        $smsBody = $this->render('templates', 'ticket_message', $placeholders);
        $portalBody = $this->render('portal', 'ticket_message', $placeholders);

        if (filled($contact->phone_number)) {
            $this->sms->send($contact->phone_number, $smsBody);
        }

        $contact->notify(new PartnerPortalNotification(
            title: $this->titleFor('ticket_message'),
            body: Str::limit($portalBody, 280),
            template: 'ticket_message',
            ticketPublicId: $ticket->public_id,
            ttNumber: $ticket->tt_number,
        ));
    }

    /**
     * Skip alerts when the same party keeps sending in a short window —
     * long threads would otherwise flood SMS/in-app notifications.
     */
    protected function shouldNotifyForChatMessage(Ticket $ticket, Contact|User $author, TicketComment $comment): bool
    {
        $quietMinutes = max(1, (int) config('vas.chat_notify_quiet_minutes', 10));

        $previous = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_public', true)
            ->where('id', '<', $comment->id)
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return true;
        }

        $sameParty = $previous->author_type === $author::class
            && (int) $previous->author_id === (int) $author->id;

        if (! $sameParty) {
            return true;
        }

        return $previous->created_at === null
            || $previous->created_at->lt(now()->subMinutes($quietMinutes));
    }

    public function companyChangeRequested(CompanyChangeRequest $request): void
    {
        $request->loadMissing(['contact', 'company', 'targetContact']);

        if ($request->type === \App\Enums\CompanyChangeType::Attach) {
            $owner = $request->company?->ownerContact();
            if ($owner) {
                $placeholders = [
                    'contact_name' => $owner->name ?: 'Partner',
                    'applicant_name' => $request->contact?->name ?: 'A partner',
                    'company_name' => $request->company?->name ?: 'your company',
                    'company_tin' => $request->company?->tin ?: '',
                ];
                $smsBody = $this->render('templates', 'company_membership_requested', $placeholders);
                $portalBody = $this->render('portal', 'company_membership_requested', $placeholders);

                if (filled($owner->phone_number)) {
                    $this->sms->send($owner->phone_number, $smsBody);
                }

                $owner->notify(new PartnerPortalNotification(
                    title: $this->titleFor('company_membership_requested'),
                    body: Str::limit($portalBody, 280),
                    template: 'company_membership_requested',
                    url: '/portal/company',
                ));
            }

            return;
        }

        $body = match ($request->type) {
            \App\Enums\CompanyChangeType::TransferOwnership => sprintf(
                '%s requests ownership transfer of %s (%s) to %s.',
                $request->contact?->name ?: 'Owner',
                $request->company?->name ?: 'a company',
                $request->company?->tin ?: 'TIN number n/a',
                $request->targetContact?->name ?: 'a member',
            ),
            default => sprintf(
                '%s submitted %s for %s (%s).',
                $request->contact?->name ?: 'A partner',
                $request->type->label(),
                $request->company?->name ?: 'a company',
                $request->company?->tin ?: 'TIN number n/a',
            ),
        };

        $this->notifyStaffDatabase(
            $this->managementUsers(),
            $request->type->label().' pending',
            $body,
            null,
            CompanyChangeRequestResource::getUrl('view', ['record' => $request]),
        );
    }

    public function companyChangeDecided(CompanyChangeRequest $request): void
    {
        $request->loadMissing(['contact', 'company', 'targetContact']);
        $contact = $request->contact;
        if (! $contact) {
            return;
        }

        $approved = $request->status === \App\Enums\CompanyChangeStatus::Approved;
        $template = match (true) {
            $request->type === \App\Enums\CompanyChangeType::Attach && $approved => 'company_attach_approved',
            $request->type === \App\Enums\CompanyChangeType::Attach && ! $approved => 'company_attach_rejected',
            $request->type === \App\Enums\CompanyChangeType::TransferOwnership && $approved => 'company_transfer_approved',
            $request->type === \App\Enums\CompanyChangeType::TransferOwnership && ! $approved => 'company_transfer_rejected',
            $request->type === \App\Enums\CompanyChangeType::Detach && $approved => 'company_detach_approved',
            default => 'company_detach_rejected',
        };

        $placeholders = [
            'contact_name' => $contact->name ?: 'Partner',
            'company_name' => $request->company?->name ?: 'the company',
            'company_tin' => $request->company?->tin ?: '',
            'applicant_name' => $request->targetContact?->name ?: 'the new owner',
            'note' => filled($request->admin_note) ? trim((string) $request->admin_note) : '',
            'tt_number' => '',
            'service' => '',
            'requisition' => '',
            'status' => $request->status->label(),
        ];

        $smsBody = $this->render('templates', $template, $placeholders);
        $portalBody = $this->render('portal', $template, $placeholders);
        if (filled($request->admin_note)) {
            $portalBody = rtrim($portalBody, '.').'. '.trim((string) $request->admin_note);
        }

        if (filled($contact->phone_number)) {
            $this->sms->send($contact->phone_number, $smsBody);
        }

        $contact->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit($portalBody, 280),
            template: $template,
            url: '/portal/company',
        ));

        // Also notify proposed new owner on transfer decisions.
        if ($request->type === \App\Enums\CompanyChangeType::TransferOwnership && $request->targetContact) {
            $target = $request->targetContact;
            $targetPlaceholders = [
                ...$placeholders,
                'contact_name' => $target->name ?: 'Partner',
            ];
            $targetSms = $this->render('templates', $template, $targetPlaceholders);
            $targetPortal = $this->render('portal', $template, $targetPlaceholders);
            if (filled($target->phone_number)) {
                $this->sms->send($target->phone_number, $targetSms);
            }
            $target->notify(new PartnerPortalNotification(
                title: $this->titleFor($template),
                body: Str::limit($targetPortal, 280),
                template: $template,
                url: '/portal/company',
            ));
        }
    }

    public function companyProfileSubmitted(Contact $contact, ?Company $company = null): void
    {
        $contact->loadMissing('company');
        $company ??= $contact->company;

        // Drop stale company-profile notices so a new submission is not mixed with
        // old approved / rejected / pending messages that confuse partners.
        $this->clearCompanyProfileNotifications($contact);

        $this->notifyStaffDatabase(
            $this->managementUsers(),
            'Company profile pending',
            sprintf(
                '%s submitted company %s (TIN number %s) for approval.',
                $contact->name ?: 'A partner',
                $company?->name ?: 'profile',
                $company?->tin ?: 'n/a',
            ),
            null,
            $company
                ? \App\Filament\Resources\Companies\CompanyResource::getUrl('view', ['record' => $company])
                : null,
        );

        // In-app notice to the partner while admin review is pending.
        $template = 'company_profile_pending';
        $placeholders = [
            'contact_name' => $contact->name ?: 'Partner',
            'company_name' => $company?->name ?: 'your organisation',
            'company_tin' => $company?->tin ?: 'n/a',
        ];
        $portalBody = $this->render('portal', $template, $placeholders);

        $contact->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit($portalBody, 280),
            template: $template,
            url: '/portal/company',
        ));
    }

    /**
     * Remove in-app company profile notifications for a partner.
     */
    public function clearCompanyProfileNotifications(Contact $contact): void
    {
        $templates = [
            'company_profile_pending',
            'company_profile_approved',
            'company_profile_rejected',
            'company_tin_validated',
            'profile_completed',
        ];

        $contact->notifications()
            ->where(function ($query) use ($templates): void {
                foreach ($templates as $i => $template) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $query->{$method}('data->template', $template);
                }
            })
            ->delete();
    }

    public function companyProfileDecided(Company $company, Contact $owner, bool $approved): void
    {
        $this->clearCompanyProfileNotifications($owner);

        $template = $approved ? 'company_profile_approved' : 'company_profile_rejected';
        $placeholders = [
            'contact_name' => $owner->name ?: 'Partner',
            'company_name' => $company->name ?: 'your organisation',
            'company_tin' => $company->tin ?: '',
            'note' => filled($company->approval_note) ? trim((string) $company->approval_note) : '',
            'tt_number' => '',
            'service' => '',
            'requisition' => '',
            'status' => $approved ? 'approved' : 'rejected',
        ];

        $smsBody = $this->render('templates', $template, $placeholders);
        $portalBody = $this->render('portal', $template, $placeholders);
        if (! $approved && filled($company->approval_note)) {
            $portalBody = rtrim($portalBody, '.').'. '.trim((string) $company->approval_note);
        }

        if (filled($owner->phone_number)) {
            $this->sms->send($owner->phone_number, $smsBody);
        }

        $owner->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit($portalBody, 280),
            template: $template,
            url: '/portal/company',
        ));
    }

    /**
     * Milestone: admin confirmed company TIN number — partners may submit service requests.
     * Notifies active members (SMS + portal). Bulk “welcome back” campaigns stay separate.
     */
    public function companyTinValidated(Company $company): void
    {
        $company->loadMissing(['activeMembers']);

        $recipients = $company->activeMembers;
        if ($recipients->isEmpty()) {
            $owner = $company->ownerContact();
            $recipients = $owner ? collect([$owner]) : collect();
        }

        $template = 'company_tin_validated';
        $sentPhones = [];

        foreach ($recipients as $contact) {
            if (! $contact instanceof Contact) {
                continue;
            }

            $placeholders = [
                'contact_name' => $contact->name ?: 'Partner',
                'company_name' => $company->name ?: 'your organisation',
                'company_tin' => $company->tin ?: '',
            ];

            $smsBody = $this->render('templates', $template, $placeholders);
            $portalBody = $this->render('portal', $template, $placeholders);

            $phone = trim((string) $contact->phone_number);
            if ($phone !== '' && ! isset($sentPhones[$phone])) {
                $this->sms->send($phone, $smsBody);
                $sentPhones[$phone] = true;
            }

            $contact->notify(new PartnerPortalNotification(
                title: $this->titleFor($template),
                body: Str::limit($portalBody, 280),
                template: $template,
                url: '/portal',
            ));
        }
    }

    /**
     * ERCA found the TIN number but legal name ≠ company name (consent still needed).
     * Queues SMS on the bulk queue to the owner phone and/or company phone.
     */
    public function companyErcaNameMismatch(Company $company): void
    {
        $this->notifyCompanyTinIssue(
            $company,
            'company_erca_name_mismatch',
            [
                'legal_name' => $company->legal_name ?: '—',
            ],
        );
    }

    /**
     * Automated scan: company has an invalid TIN number (not 10-digit Ethiopian).
     * Queues SMS on the bulk queue to the owner phone and/or company phone.
     */
    public function companyTinInvalid(Company $company, bool $hadFalseApproval = false): void
    {
        $this->notifyCompanyTinIssue(
            $company,
            'company_tin_invalid',
            [
                'note' => $hadFalseApproval
                    ? 'Previous TIN number approval was cleared.'
                    : 'Please submit a valid TIN number for approval.',
            ],
        );
    }

    /**
     * Valid 10-digit TIN number that ERCA does not recognise (awaiting verification / not_found).
     */
    public function companyTinNotFoundInErca(Company $company): void
    {
        $this->notifyCompanyTinIssue($company, 'company_tin_not_found_erca');
    }

    /**
     * @param  array<string, string>  $extraPlaceholders
     */
    protected function notifyCompanyTinIssue(Company $company, string $template, array $extraPlaceholders = []): void
    {
        $company->loadMissing(['activeMembers']);

        $portalUrl = rtrim((string) config('vas.frontend_url', ''), '/');
        if ($portalUrl !== '') {
            $portalUrl .= '/login';
        }

        $bulkQueue = (string) config('notifications.sms_queues.bulk', 'sms');
        $placeholders = array_merge([
            'contact_name' => 'Partner',
            'company_name' => $company->name ?: 'your organisation',
            'company_tin' => $company->tin ?: '—',
            'note' => '',
            'portal_url' => $portalUrl !== '' ? $portalUrl : 'the VAS Partners portal',
        ], $extraPlaceholders);

        $smsBody = $this->render('templates', $template, $placeholders);
        $portalBody = $this->render('portal', $template, $placeholders);

        $sentPhones = [];

        $queueSms = function (mixed $phone) use (&$sentPhones, $smsBody, $bulkQueue): void {
            $raw = trim((string) $phone);
            if ($raw === '') {
                return;
            }
            $normalized = $this->sms->normalizePhone($raw);
            if ($normalized === '' || isset($sentPhones[$normalized])) {
                return;
            }
            if (! $this->sms->ensurePhoneIsLocal($normalized)) {
                return;
            }
            $this->sms->send($normalized, $smsBody, $bulkQueue);
            $sentPhones[$normalized] = true;
        };

        $owner = $company->ownerContact();
        if ($owner instanceof Contact) {
            $queueSms($owner->phone_number);
            $owner->notify(new PartnerPortalNotification(
                title: $this->titleFor($template),
                body: Str::limit($portalBody, 280),
                template: $template,
                url: '/portal/company',
            ));
        }

        $queueSms($company->phone);

        if ($sentPhones === []) {
            foreach ($company->activeMembers as $contact) {
                if (! $contact instanceof Contact) {
                    continue;
                }
                $queueSms($contact->phone_number);
                $contact->notify(new PartnerPortalNotification(
                    title: $this->titleFor($template),
                    body: Str::limit($portalBody, 280),
                    template: $template,
                    url: '/portal/company',
                ));
            }
        }
    }

    public function memberLeftCompany(Company $company, Contact $owner, Contact $member, ?string $note = null): void
    {
        $placeholders = [
            'contact_name' => $owner->name ?: 'Partner',
            'applicant_name' => $member->name ?: 'A partner',
            'company_name' => $company->name ?: 'your company',
            'company_tin' => $company->tin ?: '',
            'note' => filled($note) ? trim($note) : '',
        ];

        $smsBody = $this->render('templates', 'company_member_left', $placeholders);
        $portalBody = $this->render('portal', 'company_member_left', $placeholders);

        if (filled($owner->phone_number)) {
            $this->sms->send($owner->phone_number, $smsBody);
        }

        $owner->notify(new PartnerPortalNotification(
            title: $this->titleFor('company_member_left'),
            body: Str::limit($portalBody, 280),
            template: 'company_member_left',
            url: '/portal/company',
        ));
    }

    /**
     * Owner added a member — always active. Notify the member by SMS + portal inbox.
     * No invite OTP; they sign in with their mobile number.
     */
    public function memberAddedToCompany(Company $company, Contact $member, Contact $owner): void
    {
        $portalUrl = rtrim((string) config('vas.frontend_url', ''), '/');
        $placeholders = [
            'contact_name' => $member->name ?: 'Partner',
            'company_name' => $company->name ?: 'your organisation',
            'company_tin' => $company->tin ?: '',
            'owner_name' => $owner->name ?: 'your company owner',
            'portal_url' => $portalUrl !== '' ? $portalUrl.'/login' : 'the VAS Partners portal',
        ];

        $smsBody = $this->render('templates', 'company_member_added', $placeholders);
        $portalBody = $this->render('portal', 'company_member_added', $placeholders);

        if (filled($member->phone_number)) {
            $this->sms->send($member->phone_number, $smsBody);
        }

        $member->notify(new PartnerPortalNotification(
            title: $this->titleFor('company_member_added'),
            body: Str::limit($portalBody, 280),
            template: 'company_member_added',
            url: '/portal',
        ));
    }

    public function profileCompleted(Contact $contact): void
    {
        $placeholders = [
            'contact_name' => $contact->name ?: 'Partner',
            'company_name' => $contact->company_name ?: 'your organisation',
        ];
        $smsBody = $this->render('templates', 'profile_completed', $placeholders);
        $portalBody = $this->render('portal', 'profile_completed', $placeholders);

        if (filled($contact->phone_number)) {
            $this->sms->send($contact->phone_number, $smsBody);
        }

        $contact->notify(new PartnerPortalNotification(
            title: $this->titleFor('profile_completed'),
            body: Str::limit(preg_replace('/\s+/', ' ', $portalBody) ?? $portalBody, 280),
            template: 'profile_completed',
        ));
    }

    protected function notifyPartner(Ticket $ticket, string $template, ?string $note = null): void
    {
        $ticket->loadMissing(['contact', 'service', 'requisition']);
        $contact = $ticket->contact;

        if (! $contact) {
            return;
        }

        $placeholders = [
            'contact_name' => $contact->name ?: 'Partner',
            'company_name' => $contact->company_name ?: 'your organisation',
            'tt_number' => $ticket->tt_number,
            'service' => $ticket->service?->name ?: 'VAS service',
            'requisition' => $ticket->requisition?->name ?: 'request',
            'status' => $ticket->status?->label() ?: (string) $ticket->status?->value,
            'note' => filled($note) ? trim((string) $note) : '',
        ];

        $smsBody = $this->render('templates', $template, $placeholders);
        $portalBody = $this->render('portal', $template, $placeholders);

        if (filled($note) && in_array($template, ['documents_need_attention', 'ticket_rejected'], true)) {
            $portalBody = rtrim($portalBody, '.').'. '.trim((string) $note);
        }

        if (filled($contact->phone_number)) {
            $this->sms->send($contact->phone_number, $smsBody);
        } else {
            Log::info('SMS skipped — contact has no phone', [
                'ticket' => $ticket->tt_number,
                'template' => $template,
            ]);
        }

        $contact->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit($portalBody, 280),
            template: $template,
            ticketPublicId: $ticket->public_id,
            ttNumber: $ticket->tt_number,
        ));
    }

    /** @param  \Illuminate\Support\Collection<int, User>|iterable<User>  $users */
    protected function notifyStaffDatabase(
        iterable $users,
        string $title,
        string $body,
        ?Ticket $ticket = null,
        ?string $url = null,
        string $icon = 'heroicon-o-building-office-2',
    ): void {
        $actionUrl = $url;
        if (! $actionUrl && $ticket) {
            $actionUrl = TicketResource::getUrl('view', ['record' => $ticket]);
        }

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $notification = FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->icon($icon)
                ->info();

            if ($actionUrl) {
                $notification->actions([
                    Action::make('view')
                        ->label('Open')
                        ->button()
                        ->url($actionUrl, shouldOpenInNewTab: false),
                ]);
            }

            $notification->sendToDatabase($user);
        }
    }

    /** Active user with the Account Manager role — otherwise null (do not notify / route). */
    protected function activeAccountManager(?User $user): ?User
    {
        if (! $user || ! $user->isAssignableAccountManager()) {
            return null;
        }

        return $user;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function managementUsers()
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_management', true)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['super_admin', 'supervisor']));
            })
            ->get();
    }

    protected function titleFor(string $template): string
    {
        return match ($template) {
            'ticket_submitted' => 'Request received',
            'ticket_in_progress' => 'Under review',
            'documents_need_attention' => 'Documents required',
            'documents_passed' => 'Documents accepted',
            'documents_incomplete_auto' => 'Documents incomplete',
            'ticket_completed' => 'Request completed',
            'ticket_rejected' => 'Request not approved',
            'ticket_closed' => 'Request closed',
            'profile_completed' => 'Profile updated',
            'ticket_message' => 'New message on your request',
            'company_attach_approved' => 'Company attach approved',
            'company_attach_rejected' => 'Company attach rejected',
            'company_detach_approved' => 'Company detach approved',
            'company_detach_rejected' => 'Company detach rejected',
            'company_membership_requested' => 'Membership request',
            'company_profile_pending' => 'Company waiting for approval',
            'company_profile_approved' => 'Company approved',
            'company_tin_validated' => 'TIN number confirmed',
            'company_tin_invalid' => 'TIN number invalid',
            'company_tin_not_found_erca' => 'TIN number not found in ERCA',
            'company_erca_name_mismatch' => 'TIN number / ERCA name mismatch',
            'company_profile_rejected' => 'Company needs updates',
            'company_member_left' => 'Member left company',
            'company_member_added' => 'Added to company',
            'company_transfer_approved' => 'Ownership transfer approved',
            'company_transfer_rejected' => 'Ownership transfer rejected',
            default => 'Portal update',
        };
    }

    protected function render(string $group, string $template, array $placeholders): string
    {
        $message = config("notifications.{$group}.{$template}");

        if (! is_string($message) || $message === '') {
            throw new \InvalidArgumentException("Notification template '{$group}.{$template}' not found.");
        }

        foreach ($placeholders as $key => $value) {
            $message = str_replace('{'.$key.'}', (string) $value, $message);
        }

        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }
}
