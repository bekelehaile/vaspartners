<?php

namespace App\Console\Commands;

use App\Services\SubscriptionLifecycleService;
use App\Services\TicketWorkflowService;
use Illuminate\Console\Command;

class OpenDueRenewalTicketsCommand extends Command
{
    protected $signature = 'vas:open-due-renewals';

    protected $description = 'Open renewal service requests for subscriptions in the renewal lead window';

    public function handle(SubscriptionLifecycleService $lifecycle, TicketWorkflowService $workflow): int
    {
        $created = $lifecycle->openDueRenewalTickets($workflow);
        $this->info("Opened {$created} renewal ticket(s).");

        return self::SUCCESS;
    }
}
