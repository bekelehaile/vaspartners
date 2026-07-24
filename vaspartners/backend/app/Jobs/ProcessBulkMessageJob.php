<?php

namespace App\Jobs;

use App\Models\BulkMessage;
use App\Services\BulkMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBulkMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $bulkMessageId,
    ) {
        $this->onQueue('sms');
    }

    public function handle(BulkMessageService $bulkMessages): void
    {
        $message = BulkMessage::query()->find($this->bulkMessageId);
        if (! $message) {
            return;
        }

        $bulkMessages->dispatchPending($message);
    }
}
