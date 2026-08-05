<?php

namespace App\Notifications;

use App\Models\AppSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notification for Fayda partner portal contacts.
 */
class PartnerPortalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $template,
        public ?string $ticketPublicId = null,
        public ?string $ttNumber = null,
        public ?string $url = null,
    ) {
        $this->onQueue((string) config('queue.names.default', 'default'));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! AppSetting::partnerInAppEnabled()) {
            return [];
        }

        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $url = $this->url
            ?: ($this->ttNumber
                ? '/portal/requests/'.$this->ttNumber
                : ($this->ticketPublicId
                    ? '/portal/requests/'.$this->ticketPublicId
                    : '/portal'));

        return [
            'title' => $this->title,
            'body' => $this->body,
            'template' => $this->template,
            'ticket_public_id' => $this->ticketPublicId,
            'tt_number' => $this->ttNumber,
            'url' => $url,
        ];
    }
}
