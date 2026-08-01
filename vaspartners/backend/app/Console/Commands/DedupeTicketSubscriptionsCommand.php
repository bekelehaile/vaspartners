<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Legacy MVAS imports often linked many historical tickets to one live subscription.
 * Keep only the activating ticket; clear subscription_id on the rest so the list is not confusing.
 */
class DedupeTicketSubscriptionsCommand extends Command
{
    protected $signature = 'vas:dedupe-ticket-subscriptions
                            {--dry-run : Show what would be cleared without writing}
                            {--limit=0 : Max subscription groups to process (0 = all)}';

    protected $description = 'When multiple tickets point at one subscription, keep activated_by_ticket_id only and clear the rest';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $groups = Ticket::query()
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, count(*) as c')
            ->groupBy('subscription_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('subscription_id')
            ->pluck('c', 'subscription_id');

        if ($groups->isEmpty()) {
            $this->info('No duplicate ticket→subscription links found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Duplicate groups: '.$groups->count()
            .' (extra links ≈ '.$groups->sum(fn ($c) => (int) $c - 1).')');

        $processed = 0;
        $cleared = 0;
        $kept = 0;
        $skipped = 0;

        foreach ($groups as $subscriptionId => $count) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $processed++;
            $subscription = Subscription::query()->find($subscriptionId);
            $ticketIds = Ticket::query()
                ->where('subscription_id', $subscriptionId)
                ->orderBy('id')
                ->pluck('id');

            $keepId = null;
            if ($subscription?->activated_by_ticket_id
                && $ticketIds->contains((int) $subscription->activated_by_ticket_id)) {
                $keepId = (int) $subscription->activated_by_ticket_id;
            } else {
                // Fallback: earliest ticket (usually the original activation request).
                $keepId = (int) $ticketIds->first();
                $skipped++;
            }

            $clearIds = $ticketIds->reject(fn ($id) => (int) $id === $keepId)->values();
            if ($clearIds->isEmpty()) {
                continue;
            }

            $kept++;
            $cleared += $clearIds->count();

            $this->line(sprintf(
                'sub #%d: keep ticket #%d, clear %d other(s)%s',
                $subscriptionId,
                $keepId,
                $clearIds->count(),
                $dryRun ? ' [dry-run]' : '',
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($clearIds, $subscriptionId, $keepId): void {
                Ticket::query()
                    ->whereIn('id', $clearIds->all())
                    ->where('subscription_id', $subscriptionId)
                    ->update(['subscription_id' => null]);
            });

            Log::info('vas:dedupe-ticket-subscriptions', [
                'subscription_id' => $subscriptionId,
                'kept_ticket_id' => $keepId,
                'cleared_ticket_ids' => $clearIds->all(),
            ]);
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Done. groups={$processed} kept={$kept} cleared_links={$cleared} fallback_keep={$skipped}");

        return self::SUCCESS;
    }
}
