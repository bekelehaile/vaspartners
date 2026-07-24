<?php

namespace App\Console\Commands;

use App\Services\Migration\RemapMigratedPublicIdsService;
use Illuminate\Console\Command;

class RemapMigratedPublicIdsCommand extends Command
{
    protected $signature = 'vas:remap-migrated-ids
                            {--dry-run : Show counts without writing}
                            {--force : Required to write changes}';

    protected $description = 'Remap legacy MVAS hex ticket numbers and ULID subscription ids to YmdH + 2 random digits from created/started timestamps';

    public function handle(RemapMigratedPublicIdsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('force');

        if ($dryRun && ! $this->option('dry-run')) {
            $this->warn('Refusing to write without --force (pass --dry-run to preview).');
        }

        $stats = $service->remap($dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['tickets remapped', $stats['tickets']],
                ['tickets skipped', $stats['skipped_tickets']],
                ['subscriptions remapped', $stats['subscriptions']],
                ['subscriptions skipped', $stats['skipped_subscriptions']],
                ['mode', $dryRun ? 'dry-run' : 'written'],
            ],
        );

        return self::SUCCESS;
    }
}
