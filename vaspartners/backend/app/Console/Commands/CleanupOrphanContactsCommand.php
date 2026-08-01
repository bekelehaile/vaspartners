<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Permanently remove soft-deleted contacts and optionally purge live orphans.
 * Soft-deleted rows are not kept — partners who abandon onboarding are deleted hard.
 */
class CleanupOrphanContactsCommand extends Command
{
    protected $signature = 'vas:cleanup-orphan-contacts
                            {--days=7 : Only purge live orphans older than this many days}
                            {--chunk=200 : Batch size}
                            {--dry-run : List counts without deleting}
                            {--trashed-only : Only permanently delete soft-deleted contacts}
                            {--skip-trashed : Do not purge soft-deleted contacts}';

    protected $description = 'Permanently delete soft-deleted contacts and unused orphan contacts (no soft-delete retained)';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $trashedOnly = (bool) $this->option('trashed-only');
        $skipTrashed = (bool) $this->option('skip-trashed');

        $purgedTrashed = 0;
        $purgedOrphans = 0;

        if (! $skipTrashed) {
            $purgedTrashed = $this->purgeTrashed($chunk, $dryRun);
        }

        if (! $trashedOnly) {
            $purgedOrphans = $this->purgeLiveOrphans($days, $chunk, $dryRun);
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Done. trashed_purged={$purgedTrashed} orphans_purged={$purgedOrphans}");

        Log::info('vas:cleanup-orphan-contacts', [
            'dry_run' => $dryRun,
            'trashed_purged' => $purgedTrashed,
            'orphans_purged' => $purgedOrphans,
            'days' => $days,
        ]);

        return self::SUCCESS;
    }

    private function purgeTrashed(int $chunk, bool $dryRun): int
    {
        $query = Contact::onlyTrashed()->orderBy('id');
        $total = (clone $query)->count();

        $this->info(($dryRun ? '[dry-run] ' : '')."Soft-deleted contacts to permanently remove: {$total}");

        if ($total === 0 || $dryRun) {
            return $dryRun ? $total : 0;
        }

        $deleted = 0;

        $query->chunkById($chunk, function ($contacts) use (&$deleted): void {
            foreach ($contacts as $contact) {
                /** @var Contact $contact */
                try {
                    $this->forcePurgeContact($contact);
                    $deleted++;
                } catch (Throwable $e) {
                    $this->error("Failed contact #{$contact->id}: {$e->getMessage()}");
                    report($e);
                }
            }
        });

        $this->info("Permanently deleted {$deleted} soft-deleted contact(s).");

        return $deleted;
    }

    private function purgeLiveOrphans(int $days, int $chunk, bool $dryRun): int
    {
        $query = Contact::query()->orphans();

        if ($days > 0) {
            $query->where('created_at', '<=', now()->subDays($days));
        }

        $total = (clone $query)->count();
        $this->info(($dryRun ? '[dry-run] ' : '')."Live orphan contacts older than {$days} day(s): {$total}");

        if ($total === 0 || $dryRun) {
            return $dryRun ? $total : 0;
        }

        $deleted = 0;

        $query->orderBy('id')->chunkById($chunk, function ($contacts) use (&$deleted): void {
            DB::transaction(function () use ($contacts, &$deleted): void {
                foreach ($contacts as $contact) {
                    /** @var Contact $contact */
                    if (! $contact->isSafeToSoftDelete()) {
                        continue;
                    }

                    $this->forcePurgeContact($contact);
                    $deleted++;
                }
            });
        });

        $this->info("Permanently deleted {$deleted} orphan contact(s).");

        return $deleted;
    }

    private function forcePurgeContact(Contact $contact): void
    {
        $contact->tokens()->delete();
        $contact->notifications()->delete();
        $contact->forceDelete();
    }
}
