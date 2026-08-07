<?php

namespace App\Console\Commands;

use App\Services\TicketWorkflowService;
use Illuminate\Console\Command;

/**
 * Close Rejected tickets that were not resubmitted within the grace window (default 14 days).
 */
class CloseStaleRejectedTicketsCommand extends Command
{
    protected $signature = 'vas:close-stale-rejected
                            {--days= : Grace days after rejection before system close (default from config)}
                            {--dry-run : List matches without closing}
                            {--limit=0 : Max tickets to close (0 = no limit)}';

    protected $description = 'System-close Rejected tickets after the partner resubmit grace window (default 2 weeks)';

    public function handle(TicketWorkflowService $workflow): int
    {
        $days = (int) ($this->option('days') ?: config('vas.rejected_ticket_auto_close_days', 14));
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $this->info(($dryRun ? '[dry-run] ' : '')."Closing Rejected tickets older than {$days} day(s)…");

        $result = $workflow->closeStaleRejectedTickets($days, $limit, $dryRun);

        $this->info(sprintf(
            'Done. scanned=%d closed=%d errors=%d',
            $result['scanned'],
            $result['closed'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
