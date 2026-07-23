<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Customer;
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
                '%s submitted %s for %s.',
                $ticket->customer?->company_name ?: $ticket->customer?->name ?: 'A partner',
                $ticket->tt_number,
                $ticket->service?->name ?: 'a service',
            ),
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
            $assignee = User::query()->find($ticket->assigned_to_user_id);
            if ($assignee) {
                $this->notifyStaffDatabase(
                    collect([$assignee]),
                    'Ticket assigned to you',
                    sprintf('%s (%s) is ready for your review.', $ticket->tt_number, $ticket->service?->name ?: 'VAS'),
                    $ticket,
                );
            }
        }
    }

    public function documentsNeedAttention(Ticket $ticket, ?string $note = null): void
    {
        $this->notifyPartner($ticket, 'documents_need_attention', $note);
    }

    public function approvalNeeded(Ticket $ticket, User $approver): void
    {
        $this->notifyStaffDatabase(
            collect([$approver]),
            'Approval required',
            sprintf('%s needs your decision (%s).', $ticket->tt_number, $ticket->service?->name ?: 'VAS'),
            $ticket,
        );
    }

    /** Notify the other party when a public chat message is posted (debounced for rapid back-and-forth). */
    public function ticketMessagePosted(Ticket $ticket, Customer|User $author, TicketComment $comment): void
    {
        if (! $this->shouldNotifyForChatMessage($ticket, $author, $comment)) {
            return;
        }

        $ticket->loadMissing(['customer', 'service', 'assignee']);
        $preview = Str::limit(trim((string) $comment->body), 120);
        if ($comment->hasAttachment()) {
            $preview = trim($preview.' [PDF: '.($comment->attachment_original_name ?: 'attachment').']');
        }

        if ($author instanceof Customer) {
            $recipients = collect();
            if ($ticket->assignee) {
                $recipients->push($ticket->assignee);
            } else {
                $recipients = $this->managementUsers();
            }

            $this->notifyStaffDatabase(
                $recipients,
                'Partner message',
                sprintf('%s on %s: %s', $author->name ?: 'Partner', $ticket->tt_number, $preview),
                $ticket,
            );

            return;
        }

        // Staff → partner (portal notification only — avoid SMS spam on every reply)
        $ticket->loadMissing(['customer', 'service', 'requisition']);
        $customer = $ticket->customer;
        if (! $customer) {
            return;
        }

        $placeholders = [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
            'tt_number' => $ticket->tt_number,
            'service' => $ticket->service?->name ?: 'VAS service',
            'requisition' => $ticket->requisition?->name ?: 'request',
            'status' => $ticket->status?->label() ?: (string) $ticket->status?->value,
            'note' => $preview,
        ];
        $portalBody = $this->render('portal', 'ticket_message', $placeholders);
        $customer->notify(new PartnerPortalNotification(
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
    protected function shouldNotifyForChatMessage(Ticket $ticket, Customer|User $author, TicketComment $comment): bool
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
        $request->loadMissing(['customer', 'company']);
        $this->notifyStaffDatabase(
            $this->managementUsers(),
            $request->type->label().' pending',
            sprintf(
                '%s requested to %s %s (%s).',
                $request->customer?->name ?: 'A partner',
                $request->type === \App\Enums\CompanyChangeType::Attach ? 'join' : 'leave',
                $request->company?->name ?: 'a company',
                $request->company?->tin ?: 'TIN n/a',
            ),
            null,
            CompanyChangeRequestResource::getUrl('view', ['record' => $request]),
        );
    }

    public function companyChangeDecided(CompanyChangeRequest $request): void
    {
        $request->loadMissing(['customer', 'company']);
        $customer = $request->customer;
        if (! $customer) {
            return;
        }

        $approved = $request->status === \App\Enums\CompanyChangeStatus::Approved;
        $template = match (true) {
            $request->type === \App\Enums\CompanyChangeType::Attach && $approved => 'company_attach_approved',
            $request->type === \App\Enums\CompanyChangeType::Attach && ! $approved => 'company_attach_rejected',
            $request->type === \App\Enums\CompanyChangeType::Detach && $approved => 'company_detach_approved',
            default => 'company_detach_rejected',
        };

        $placeholders = [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $request->company?->name ?: 'the company',
            'company_tin' => $request->company?->tin ?: '',
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

        if (filled($customer->phone_number)) {
            $this->sms->send($customer->phone_number, $smsBody);
        }

        $customer->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit($portalBody, 280),
            template: $template,
            url: '/portal/company',
        ));
    }

    public function profileCompleted(Customer $customer): void
    {
        $placeholders = [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
        ];
        $smsBody = $this->render('templates', 'profile_completed', $placeholders);
        $portalBody = $this->render('portal', 'profile_completed', $placeholders);

        if (filled($customer->phone_number)) {
            $this->sms->send($customer->phone_number, $smsBody);
        }

        $customer->notify(new PartnerPortalNotification(
            title: $this->titleFor('profile_completed'),
            body: Str::limit(preg_replace('/\s+/', ' ', $portalBody) ?? $portalBody, 280),
            template: 'profile_completed',
        ));
    }

    protected function notifyPartner(Ticket $ticket, string $template, ?string $note = null): void
    {
        $ticket->loadMissing(['customer', 'service', 'requisition']);
        $customer = $ticket->customer;

        if (! $customer) {
            return;
        }

        $placeholders = [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
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

        if (filled($customer->phone_number)) {
            $this->sms->send($customer->phone_number, $smsBody);
        } else {
            Log::info('SMS skipped — customer has no phone', [
                'ticket' => $ticket->tt_number,
                'template' => $template,
            ]);
        }

        $customer->notify(new PartnerPortalNotification(
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
                ->icon('heroicon-o-building-office-2');

            if ($actionUrl) {
                $notification->actions([
                    Action::make('view')
                        ->label('Open')
                        ->url($actionUrl),
                ]);
            }

            $notification->sendToDatabase($user);
        }
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
            'ticket_completed' => 'Request completed',
            'ticket_rejected' => 'Request not approved',
            'ticket_closed' => 'Request closed',
            'profile_completed' => 'Profile updated',
            'ticket_message' => 'New message on your request',
            'company_attach_approved' => 'Company attach approved',
            'company_attach_rejected' => 'Company attach rejected',
            'company_detach_approved' => 'Company detach approved',
            'company_detach_rejected' => 'Company detach rejected',
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
