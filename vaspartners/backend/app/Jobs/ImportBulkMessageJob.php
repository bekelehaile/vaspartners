<?php

namespace App\Jobs;

use App\Models\BulkMessage;
use App\Services\BulkMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportBulkMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $bulkMessageId,
    ) {
        $this->onQueue((string) config('notifications.sms_queues.bulk', 'sms'));
    }

    public function handle(BulkMessageService $bulkMessages): void
    {
        $campaign = BulkMessage::query()->find($this->bulkMessageId);
        if (! $campaign) {
            return;
        }

        $bulkMessages->processImport($campaign);
    }

    public function failed(?Throwable $exception): void
    {
        $campaign = BulkMessage::query()->find($this->bulkMessageId);
        if (! $campaign) {
            return;
        }

        app(BulkMessageService::class)->failImport(
            $campaign,
            $exception?->getMessage() ?: 'Import job failed.',
        );
    }
}
