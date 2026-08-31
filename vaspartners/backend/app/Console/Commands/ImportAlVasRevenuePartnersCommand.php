<?php

namespace App\Console\Commands;

use App\Services\Migration\ImportAlVasRevenuePartnersService;
use Illuminate\Console\Command;

/**
 * Import / update Revenue Partners from Al VAS master Excel (Service ID key).
 *
 *   php artisan vas:import-al-vas-revenue-partners --dry-run
 *   php artisan vas:import-al-vas-revenue-partners
 */
class ImportAlVasRevenuePartnersCommand extends Command
{
    protected $signature = 'vas:import-al-vas-revenue-partners
        {--path= : XLSX or JSON path (default database/data/Al vas data-august 31-2026.xlsx)}
        {--dry-run : Report creates/updates without writing}';

    protected $description = 'Upsert Revenue Partners from Al VAS Excel (Service ID → name, service type, phone, account manager)';

    public function handle(ImportAlVasRevenuePartnersService $importer): int
    {
        $path = trim((string) $this->option('path'));
        if ($path === '') {
            $path = ImportAlVasRevenuePartnersService::DEFAULT_XLSX;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no database changes.');
        }

        try {
            $stats = $importer->import($path, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows in file', $stats['total']],
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Unchanged', $stats['unchanged']],
                ['Skipped', $stats['skipped']],
                ['Phones set/changed', $stats['phones_set']],
                ['Invalid phones', $stats['phones_invalid']],
                ['Account managers assigned', $stats['am_assigned']],
                ['Unknown account managers', $stats['am_unknown']],
            ],
        );

        if ($stats['skipped_keys'] !== []) {
            $this->warn('Skipped keys: '.implode(', ', array_slice($stats['skipped_keys'], 0, 20)));
        }

        if ($stats['unknown_am_names'] !== []) {
            $this->warn('Unknown AM names: '.implode(', ', array_slice($stats['unknown_am_names'], 0, 20)));
        }

        if ($dryRun && ($stats['created'] + $stats['updated']) > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
