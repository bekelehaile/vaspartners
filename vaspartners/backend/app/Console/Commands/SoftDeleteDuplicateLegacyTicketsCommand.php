<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Soft-delete legacy MVAS tickets that duplicate an activating request
 * (same contact + service) and no longer link to a subscription.
 */
class SoftDeleteDuplicateLegacyTicketsCommand extends Command
{
    protected $signature = 'vas:soft-delete-duplicate-legacy-tickets
                            {--dry-run : List matches without deleting}
                            {--limit=0 : Max tickets to soft-delete (0 = all)}';

    protected $description = 'Soft-delete closed/rejected legacy tickets that duplicate the subscription-activating request';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $activated = Subscription::query()
            ->whereNotNull('activated_by_ticket_id')
            ->get(['id', 'activated_by_ticket_id']);

        $candidateIds = collect();

        foreach ($activated as $subscription) {
            $keeper = Ticket::withTrashed()->find($subscription->activated_by_ticket_id);
            if (! $keeper || ! $keeper->contact_id || ! $keeper->service_id) {
                continue;
            }

            $ids = Ticket::query()
                ->whereNull('subscription_id')
                ->whereNotNull('legacy_mvas_ticket_id')
                ->where('contact_id', $keeper->contact_id)
                ->where('service_id', $keeper->service_id)
                ->where('id', '!=', $keeper->id)
                ->whereIn('status', ['closed', 'completed', 'rejected'])
                ->pluck('id');

            $candidateIds = $candidateIds->merge($ids);
        }

        $candidateIds = $candidateIds->unique()->sort()->values();
        if ($limit > 0) {
            $candidateIds = $candidateIds->take($limit)->values();
        }

        if ($candidateIds->isEmpty()) {
            $this->info('No duplicate legacy tickets to soft-delete.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Soft-deleting '.$candidateIds->count().' duplicate legacy ticket(s)…');

        $deleted = 0;
        foreach ($candidateIds->chunk(200) as $chunk) {
            $tickets = Ticket::query()->whereIn('id', $chunk->all())->get();
            foreach ($tickets as $ticket) {
                $this->line(sprintf(
                    '%s %s status=%s%s',
                    $ticket->tt_number,
                    $ticket->id,
                    $ticket->status instanceof \BackedEnum ? $ticket->status->value : (string) $ticket->status,
                    $dryRun ? ' [dry-run]' : '',
                ));

                if ($dryRun) {
                    continue;
                }

                $ticket->delete(); // SoftDeletes
                $deleted++;
            }
        }

        if (! $dryRun) {
            Log::info('vas:soft-delete-duplicate-legacy-tickets', [
                'deleted_count' => $deleted,
                'ticket_ids_sample' => $candidateIds->take(50)->all(),
            ]);
        }

        $this->info(($dryRun ? '[dry-run] Would soft-delete ' : 'Soft-deleted ').($dryRun ? $candidateIds->count() : $deleted).' ticket(s).');

        return self::SUCCESS;
    }
}
