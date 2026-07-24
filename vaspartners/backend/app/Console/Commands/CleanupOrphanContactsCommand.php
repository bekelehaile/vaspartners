<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupOrphanContactsCommand extends Command
{
    protected $signature = 'vas:cleanup-orphan-contacts
                            {--days=7 : Only delete contacts older than this many days}
                            {--chunk=500 : Soft-delete batch size}
                            {--dry-run : List counts without deleting}
                            {--force : Permanently delete instead of soft-delete}';

    protected $description = 'Remove contacts with no memberships, tickets, or subscriptions (scheduled cleanup)';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Contact::query()->orphans();

        if ($days > 0) {
            $query->where('created_at', '<=', now()->subDays($days));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No orphan contacts to clean up.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Found {$total} orphan contact(s) older than {$days} day(s).");

        if ($dryRun) {
            return self::SUCCESS;
        }

        $deleted = 0;

        $query->orderBy('id')->chunkById($chunk, function ($contacts) use (&$deleted, $force): void {
            DB::transaction(function () use ($contacts, &$deleted, $force): void {
                foreach ($contacts as $contact) {
                    /** @var Contact $contact */
                    if (! $contact->isSafeToSoftDelete()) {
                        continue;
                    }

                    if ($force) {
                        $contact->forceDelete();
                    } else {
                        $contact->delete();
                    }

                    $deleted++;
                }
            });
        });

        $verb = $force ? 'Permanently deleted' : 'Soft-deleted';
        $this->info("{$verb} {$deleted} contact(s).");

        Log::info('vas:cleanup-orphan-contacts', [
            'deleted' => $deleted,
            'force' => $force,
            'days' => $days,
        ]);

        return self::SUCCESS;
    }
}
