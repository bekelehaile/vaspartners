<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

class SendTestSmsCommand extends Command
{
    protected $signature = 'sms:test {phone : Local mobile e.g. 09xxxxxxxx} {--message=VAS Partners SMS test from Ethio telecom gateway}';

    protected $description = 'Send a test SMS via the Ethio telecom smsgw endpoint';

    public function handle(SmsService $sms): int
    {
        $phone = (string) $this->argument('phone');
        $message = (string) $this->option('message');

        if (! $sms->ensurePhoneIsLocal($phone)) {
            $this->error('Phone must be a local Ethio telecom mobile (9/7 + 8 digits).');

            return self::FAILURE;
        }

        $this->info('Dispatching SMS to '.$sms->normalizePhone($phone).'…');
        $sms->send($phone, $message);
        $this->comment('Queued on sms. Ensure the queue worker is running.');

        return self::SUCCESS;
    }
}
