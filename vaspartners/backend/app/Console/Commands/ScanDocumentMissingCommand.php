<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanDocumentMissingCommand extends Command
{
    protected $signature = 'vas:scan-document-missing
                            {--dry-run : List matches without rejecting or sending SMS}
                            {--no-sms : Reject without partner SMS / portal notification}
                            {--limit=0 : Max tickets to process (0 = no limit)}
                            {--chunk=100 : Tickets loaded per batch}';

    protected $description = 'Reject open/in-progress/closed/completed requests missing any hard-required document and notify partners';

    public function handle(TicketWorkflowService $workflow): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $notify = ! (bool) $this->option('no-sms');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));
        $statuses = $workflow->statusesThatMustRejectWhenIncomplete();

        $query = Ticket::query()
            ->with(['contact:id,name,phone_number', 'service:id,name', 'requisition:id,name', 'documents:id,ticket_id,document_type_id'])
            ->whereIn('status', $statuses)
            ->orderBy('id');

        $scanned = 0;
        $incomplete = 0;
        $rejected = 0;
        $skipped = 0;
        $errors = 0;

        $this->info(($dryRun ? '[dry-run] ' : '').'Scanning requests for missing required documents (incl. closed)…');

        $query->chunkById($chunk, function ($tickets) use (
            $workflow,
            $dryRun,
            $notify,
            $limit,
            &$scanned,
            &$incomplete,
            &$rejected,
            &$skipped,
            &$errors,
        ): bool {
            foreach ($tickets as $ticket) {
                if ($limit > 0 && $incomplete >= $limit) {
                    return false;
                }

                $scanned++;
                /** @var Ticket $ticket */
                $status = $workflow->attachmentStatus($ticket);
                if ($status['state'] !== 'incomplete') {
                    continue;
                }

                $incomplete++;
                $label = sprintf(
                    '%s | %s | %s | missing=%d',
                    $ticket->tt_number,
                    $ticket->status?->value,
                    $ticket->service?->name ?: 'service',
                    $status['missing_count'],
                );

                if ($dryRun) {
                    $this->line('  '.$label);
                    $this->line('    '.implode('; ', $status['missing_names']));

                    continue;
                }

                try {
                    $result = $workflow->rejectForIncompleteDocuments($ticket, $notify);
                    if ($result['rejected']) {
                        $rejected++;
                        $this->line('  rejected '.$label);
                    } else {
                        $skipped++;
                        $this->line('  skipped '.$ticket->tt_number.' ('.$result['reason'].')');
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error('  error '.$ticket->tt_number.': '.$e->getMessage());
                    Log::error('vas:scan-document-missing failed', [
                        'tt_number' => $ticket->tt_number,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return ! ($limit > 0 && $incomplete >= $limit);
        });

        $summary = sprintf(
            'scanned=%d incomplete=%d rejected=%d skipped=%d errors=%d notify=%s',
            $scanned,
            $incomplete,
            $rejected,
            $skipped,
            $errors,
            $notify && ! $dryRun ? 'yes' : 'no',
        );
        $this->info(($dryRun ? '[dry-run] ' : '').$summary);

        Log::info('vas:scan-document-missing', [
            'dry_run' => $dryRun,
            'notify' => $notify,
            'scanned' => $scanned,
            'incomplete' => $incomplete,
            'rejected' => $rejected,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
