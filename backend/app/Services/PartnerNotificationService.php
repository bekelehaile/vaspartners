<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Outbound partner messages — SMS only via Ethio telecom gateway.
 */
class PartnerNotificationService
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    public function ticketSubmitted(Ticket $ticket): void
    {
        $this->sendTicketTemplate($ticket, 'ticket_submitted');
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
            $this->sendTicketTemplate($ticket, $template, $note);
        }
    }

    public function documentsNeedAttention(Ticket $ticket, ?string $note = null): void
    {
        $this->sendTicketTemplate($ticket, 'documents_need_attention', $note);
    }

    public function profileCompleted(Customer $customer): void
    {
        $phone = $customer->phone_number;
        if (! filled($phone)) {
            return;
        }

        $this->sms->send($phone, $this->render('profile_completed', [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
        ]));
    }

    protected function sendTicketTemplate(Ticket $ticket, string $template, ?string $note = null): void
    {
        $ticket->loadMissing(['customer', 'service', 'requisition']);
        $customer = $ticket->customer;
        $phone = $customer?->phone_number;

        if (! $customer || ! filled($phone)) {
            Log::info('SMS skipped — customer has no phone', [
                'ticket' => $ticket->tt_number,
                'template' => $template,
            ]);

            return;
        }

        $this->sms->send($phone, $this->render($template, [
            'customer_name' => $customer->name ?: 'Partner',
            'company_name' => $customer->company_name ?: 'your organisation',
            'tt_number' => $ticket->tt_number,
            'service' => $ticket->service?->name ?: 'VAS service',
            'requisition' => $ticket->requisition?->name ?: 'request',
            'status' => $ticket->status?->label() ?: (string) $ticket->status?->value,
            'note' => filled($note) ? $note : 'Please check the portal for details.',
        ]));
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
