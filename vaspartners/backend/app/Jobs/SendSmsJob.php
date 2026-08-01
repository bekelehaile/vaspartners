<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(
        public string $phone,
        public string $message,
    ) {
        $this->onQueue((string) config('notifications.sms_queues.default', 'sms'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('sms-global'),
        ];
    }

    public function handle(SmsService $sms): void
    {
        if (! AppSetting::partnerSmsEnabled()) {
            Log::info('SendSmsJob skipped (partner SMS disabled in App settings)', [
                'phone' => $this->phone,
            ]);

            return;
        }

        if (! $sms->ensurePhoneIsLocal($this->phone)) {
            // Do not retry non-Ethiopian / invalid numbers.
            return;
        }

        if (! $sms->sendNow($this->phone, $this->message)) {
            throw new RuntimeException("Failed to send SMS to +251{$this->phone}");
        }
    }
}
