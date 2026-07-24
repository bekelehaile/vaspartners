<?php

namespace App\Jobs;

use App\Models\BulkMessageRecipient;
use App\Services\BulkMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBulkMessageRecipientJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 10;

    public function __construct(
        public int $recipientId,
    ) {
        $this->onQueue('sms');
    }

    public function handle(BulkMessageService $bulkMessages): void
    {
        $recipient = BulkMessageRecipient::query()->with('bulkMessage')->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        $bulkMessages->sendRecipient($recipient);
    }
}
