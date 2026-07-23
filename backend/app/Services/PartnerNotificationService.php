<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Customer;
use App\Models\Ticket;
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

    public function profileCompleted(Customer $customer): void
    {
        $placeholders = [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
        ];
        $body = $this->render('profile_completed', $placeholders);

        if (filled($customer->phone_number)) {
            $this->sms->send($customer->phone_number, $body);
        }

        $customer->notify(new PartnerPortalNotification(
            title: $this->titleFor('profile_completed'),
            body: Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 280),
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
            'note' => filled($note) ? $note : 'Please check the portal for details.',
        ];

        $body = $this->render($template, $placeholders);

        if (filled($customer->phone_number)) {
            $this->sms->send($customer->phone_number, $body);
        } else {
            Log::info('SMS skipped — customer has no phone', [
                'ticket' => $ticket->tt_number,
                'template' => $template,
            ]);
        }

        $customer->notify(new PartnerPortalNotification(
            title: $this->titleFor($template),
            body: Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 280),
            template: $template,
            ticketPublicId: $ticket->public_id,
            ttNumber: $ticket->tt_number,
        ));
    }

    /** @param  \Illuminate\Support\Collection<int, User>|iterable<User>  $users */
    protected function notifyStaffDatabase(iterable $users, string $title, string $body, Ticket $ticket): void
    {
        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-ticket')
                ->actions([
                    Action::make('view')
                        ->label('Open ticket')
                        ->url(TicketResource::getUrl('view', ['record' => $ticket])),
                ])
                ->sendToDatabase($user);
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
            'ticket_submitted' => 'Request submitted',
            'ticket_in_progress' => 'Request in progress',
            'documents_need_attention' => 'Documents need attention',
            'ticket_completed' => 'Request completed',
            'ticket_rejected' => 'Request needs attention',
            'ticket_closed' => 'Request closed',
            'profile_completed' => 'Company profile saved',
            default => 'VAS Partners update',
        };
    }

    protected function render(string $template, array $placeholders): string
    {
        $message = config("notifications.templates.{$template}");

        if (! is_string($message) || $message === '') {
            throw new \InvalidArgumentException("Notification template '{$template}' not found.");
        }

        foreach ($placeholders as $key => $value) {
            $message = str_replace('{'.$key.'}', (string) $value, $message);
        }

        return trim($message);
    }
}
