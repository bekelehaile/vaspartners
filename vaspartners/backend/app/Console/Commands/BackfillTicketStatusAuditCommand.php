<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketAuditTrailService;
use Illuminate\Console\Command;

class BackfillTicketStatusAuditCommand extends Command
{
    protected $signature = 'vas:backfill-ticket-status-audit
                            {--limit=500 : Max tickets to process}
                            {--tt= : Only this TT number}';

    protected $description = 'Backfill who/when status audit rows from ticket stamps and assignment/approval logs';

    public function handle(TicketAuditTrailService $audit): int
    {
        $query = Ticket::query()->orderBy('id');
        if ($tt = $this->option('tt')) {
            $query->where('tt_number', $tt);
        }

        $limit = max(1, (int) $this->option('limit'));
        $processed = 0;
        $created = 0;

        $query->chunkById(100, function ($tickets) use ($audit, $limit, &$processed, &$created) {
            foreach ($tickets as $ticket) {
                if ($processed >= $limit) {
                    return false;
                }
                $created += $audit->backfillMissingHistory($ticket);
                $processed++;
            }

            return $processed < $limit;
        });

        $this->info("Processed {$processed} ticket(s); inserted {$created} history row(s).");

        return self::SUCCESS;
    }
}
