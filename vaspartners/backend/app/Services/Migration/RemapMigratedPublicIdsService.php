<?php

namespace App\Services\Migration;

use App\Models\Subscription;
use App\Models\Ticket;
use App\Support\TimestampPublicId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remap already-imported MVAS hex ticket numbers / ULID subscription ids
 * to year+month+day+hour + two random digits based on created/started timestamps.
 */
class RemapMigratedPublicIdsService
{
    /**
     * @return array{tickets: int, subscriptions: int, skipped_tickets: int, skipped_subscriptions: int}
     */
    public function remap(bool $dryRun = false): array
    {
        $stats = [
            'tickets' => 0,
            'subscriptions' => 0,
            'skipped_tickets' => 0,
            'skipped_subscriptions' => 0,
        ];

        $usedTicketNumbers = Ticket::query()->pluck('tt_number')->flip()->all();
        $usedSubscriptionIds = Subscription::withTrashed()->pluck('public_id')->flip()->all();

        Ticket::query()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->orderBy('id')
            ->each(function (Ticket $ticket) use (&$stats, &$usedTicketNumbers, $dryRun): void {
                if (! TimestampPublicId::looksLikeLegacyHexId($ticket->tt_number)) {
                    $stats['skipped_tickets']++;

                    return;
                }

                $newId = TimestampPublicId::generate(
                    $ticket->created_at ?? now(),
                    fn (string $id): bool => isset($usedTicketNumbers[$id]),
                );

                $usedTicketNumbers[$newId] = true;

                if (! $dryRun) {
                    $ticket->forceFill(['tt_number' => $newId])->saveQuietly();
                }

                $stats['tickets']++;
            });

        Subscription::withTrashed()
            ->orderBy('id')
            ->each(function (Subscription $subscription) use (&$stats, &$usedSubscriptionIds, $dryRun): void {
                if (! TimestampPublicId::looksLikeUlid($subscription->public_id)) {
                    $stats['skipped_subscriptions']++;

                    return;
                }

                $newId = TimestampPublicId::generate(
                    $subscription->started_at ?? $subscription->created_at ?? now(),
                    fn (string $id): bool => isset($usedSubscriptionIds[$id]),
                );

                $usedSubscriptionIds[$newId] = true;

                if (! $dryRun) {
                    DB::table('subscriptions')
                        ->where('id', $subscription->id)
                        ->update(['public_id' => $newId]);
                }

                $stats['subscriptions']++;
            });

        Log::info('Remapped migrated public ids', $stats + ['dry_run' => $dryRun]);

        return $stats;
    }
}
