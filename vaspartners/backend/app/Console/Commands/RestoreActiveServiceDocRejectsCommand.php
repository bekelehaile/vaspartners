<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\TicketWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fix old-system gap: auto doc-scan rejected finished requests while the service stayed active.
 */
class RestoreActiveServiceDocRejectsCommand extends Command
{
    protected $signature = 'vas:restore-active-service-doc-rejects
                            {--dry-run : List matches without changing status}
                            {--limit=0 : Max tickets to process (0 = no limit)}
                            {--chunk=100 : Tickets loaded per batch}';

    protected $description = 'Restore auto-rejected requests that still have an active subscription; clear failed document review';

    public function handle(TicketWorkflowService $workflow): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Ticket::query()
            ->with(['service:id,name', 'subscription:id,status,public_id'])
            ->where('status', TicketStatus::Rejected->value)
            ->whereHas('statusHistories', function ($q) {
                $q->where('to_status', TicketStatus::Rejected->value)
                    ->where(function ($inner) {
                        $inner->where('note', 'like', 'Automated document check:%')
                            ->orWhere('meta->source', 'vas:scan-document-missing');
                    });
            })
            ->orderBy('id');

        $scanned = 0;
        $restored = 0;
        $skipped = 0;
        $errors = 0;

        $this->info(($dryRun ? '[dry-run] ' : '').'Restoring auto-rejects that still have an active service…');

        $query->chunkById($chunk, function ($tickets) use (
            $workflow,
            $dryRun,
            $limit,
            &$scanned,
            &$restored,
            &$skipped,
            &$errors,
        ): bool {
            foreach ($tickets as $ticket) {
                if ($limit > 0 && $restored >= $limit) {
                    return false;
                }

                $scanned++;
                /** @var Ticket $ticket */
                if (! $workflow->ticketHasAliveService($ticket)) {
                    $skipped++;

                    continue;
                }

                $label = sprintf(
                    '%s | %s | sub=%s',
                    $ticket->tt_number,
                    $ticket->service?->name ?: 'service',
                    $ticket->subscription?->public_id ?: 'linked',
                );

                if ($dryRun) {
                    $restored++;
                    $this->line('[dry-run] '.$label);

                    continue;
                }

                try {
                    $result = $workflow->restoreAutoRejectWhenServiceAlive($ticket);
                    if ($result['restored']) {
                        $restored++;
                        if ($restored <= 10 || $restored % 50 === 0) {
                            $this->info('restored '.$label.' → '.$result['to_status']);
                        }
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('vas:restore-active-service-doc-rejects failed', [
                        'tt_number' => $ticket->tt_number,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error('error '.$ticket->tt_number.': '.$e->getMessage());
                }
            }

            return ! ($limit > 0 && $restored >= $limit);
        });

        $this->info("Done. scanned={$scanned} restored={$restored} skipped={$skipped} errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
