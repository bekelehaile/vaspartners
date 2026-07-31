<?php

namespace App\Console\Commands;

use App\Services\RemountSubscriptionsToVerifiedTinService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemountSubscriptionsToVerifiedTinCommand extends Command
{
    protected $signature = 'vas:remount-subscriptions-to-verified-tin
                            {--dry-run : Preview moves without writing}
                            {--force : Required to write changes}';

    protected $description = 'Move alive subscriptions from abandoned MVAS/unverified companies onto TIN-verified ERCA companies';

    public function handle(RemountSubscriptionsToVerifiedTinService $service): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('force');

        if ($dryRun && ! $this->option('dry-run')) {
            $this->warn('Refusing to write without --force (pass --dry-run to preview).');
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Remounting subscriptions onto TIN-verified companies…');

        $result = $service->remountAll($dryRun);

        if ($result['rows'] !== []) {
            $this->table(
                ['Sub', 'Service', 'From TIN', 'To TIN', 'Company', 'Action'],
                collect($result['rows'])->map(fn (array $row) => [
                    $row['subscription_id'],
                    $row['service'] ?? '—',
                    $row['from_tin'] ?? '—',
                    $row['to_tin'] ?? '—',
                    $row['to_name'] ?? '—',
                    $row['action'],
                ])->all(),
            );
        }

        $this->table(
            ['Metric', 'Count'],
            [
                [$dryRun ? 'would_move' : 'moved', $result['moved']],
                ['skipped_conflict', $result['skipped']],
                ['mode', $dryRun ? 'dry-run' : 'written'],
            ],
        );

        Log::info('vas:remount-subscriptions-to-verified-tin', [
            'dry_run' => $dryRun,
            'moved' => $result['moved'],
            'skipped' => $result['skipped'],
        ]);

        return self::SUCCESS;
    }
}
