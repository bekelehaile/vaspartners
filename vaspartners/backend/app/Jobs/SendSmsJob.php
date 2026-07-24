<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public string $phone,
        public string $message,
    ) {
        $this->onQueue('sms');
    }

    public function handle(SmsService $sms): void
    {
        if (! $sms->sendNow($this->phone, $this->message)) {
            throw new RuntimeException("Failed to send SMS to {$this->phone}");
        }
    }
}
