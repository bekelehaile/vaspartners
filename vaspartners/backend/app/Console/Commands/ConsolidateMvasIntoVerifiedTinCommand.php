<?php

namespace App\Console\Commands;

use App\Services\ConsolidateMvasIntoVerifiedTinService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConsolidateMvasIntoVerifiedTinCommand extends Command
{
    protected $signature = 'vas:consolidate-mvas-into-verified-tin
                            {--dry-run : Preview merges without writing}
                            {--force : Required to write changes}
                            {--tin= : Only consolidate into this verified TIN number}';

    protected $description = 'Move leftover MVAS placeholder company data onto live ERCA TIN-verified companies and soft-delete the shells';

    public function handle(ConsolidateMvasIntoVerifiedTinService $service): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('force');
        $onlyTin = trim((string) $this->option('tin'));

        if ($dryRun && ! $this->option('dry-run')) {
            $this->warn('Refusing to write without --force (pass --dry-run to preview).');
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Consolidating MVAS placeholders into TIN-verified companies…');

        if ($onlyTin !== '') {
            $pairs = array_values(array_filter(
                $service->discoverPairs(),
                fn ($pair) => (string) $pair->new_tin === $onlyTin,
            ));
            $moved = [
                'subscriptions' => 0,
                'memberships' => 0,
                'change_requests' => 0,
                'status_histories' => 0,
                'feedback' => 0,
                'revenue_partners' => 0,
                'bulk_recipients' => 0,
                'legacy_copied' => 0,
            ];
            $softDeleted = 0;
            $rows = [];
            foreach ($pairs as $pair) {
                $result = $service->consolidatePair((int) $pair->old_id, (int) $pair->new_id, $dryRun);
                foreach ($result['moved'] as $key => $count) {
                    $moved[$key] = ($moved[$key] ?? 0) + $count;
                }
                $softDeleted += $result['soft_deleted'] ? 1 : 0;
                $rows[] = $result['row'];
            }
            $result = [
                'pairs' => count($rows),
                'moved' => $moved,
                'soft_deleted' => $softDeleted,
                'rows' => $rows,
            ];
        } else {
            $result = $service->consolidateAll($dryRun);
        }

        if ($result['rows'] !== []) {
            $this->table(
                ['From TIN', 'To TIN', 'Company', 'Action', 'Details'],
                collect($result['rows'])->map(function (array $row) {
                    $counts = $row['counts'] ?? [];
                    $detail = $counts === []
                        ? '—'
                        : collect($counts)->filter()->map(fn ($v, $k) => $k.'='.$v)->implode(', ');

                    return [
                        $row['old_tin'] ?? '—',
                        $row['new_tin'] ?? '—',
                        $row['new_name'] ?? '—',
                        $row['action'] ?? '—',
                        $detail !== '' ? $detail : '—',
                    ];
                })->all(),
            );
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['pairs', $result['pairs']],
                ...collect($result['moved'])->map(fn ($v, $k) => [$k, $v])->values()->all(),
                ['soft_deleted', $result['soft_deleted']],
                ['mode', $dryRun ? 'dry-run' : 'written'],
            ],
        );

        Log::info('vas:consolidate-mvas-into-verified-tin', [
            'dry_run' => $dryRun,
            'tin' => $onlyTin !== '' ? $onlyTin : null,
            'pairs' => $result['pairs'],
            'moved' => $result['moved'],
            'soft_deleted' => $result['soft_deleted'],
        ]);

        return self::SUCCESS;
    }
}
