<?php

namespace App\Console\Commands;

use App\Services\Migration\SeedRevenueExcelSnapshotService;
use Illuminate\Console\Command;

/**
 * Re-seed revenue partners / monthly imports from committed Excel JSON snapshots.
 *
 *   php artisan vas:seed-revenue-excel
 *   php artisan vas:seed-revenue-excel --partners-only
 *   php artisan vas:seed-revenue-excel --monthly-only
 */
class SeedRevenueExcelCommand extends Command
{
    protected $signature = 'vas:seed-revenue-excel
        {--partners-only : Seed partner master list only}
        {--monthly-only : Seed monthly import batches only}
        {--link-companies : Also link partners to portal companies by phone}';

    protected $description = 'Seed revenue partners and monthly imports from database/data/revenue Excel snapshots';

    public function handle(SeedRevenueExcelSnapshotService $seeder): int
    {
        $partnersOnly = (bool) $this->option('partners-only');
        $monthlyOnly = (bool) $this->option('monthly-only');

        if (! $monthlyOnly) {
            $this->info('Seeding revenue partners from snapshot…');
            $stats = $seeder->seedPartners();
            $this->table(
                ['Created', 'Updated', 'Unchanged/skipped', 'Merged dupes', 'Snapshot total'],
                [[$stats['created'], $stats['updated'], $stats['skipped'], $stats['merged_duplicates'] ?? 0, $stats['total']]],
            );
        }

        if (! $partnersOnly) {
            $this->info('Seeding monthly revenue imports from snapshot…');
            $stats = $seeder->seedMonthlyImports();
            $this->table(
                ['Imports upserted', 'Rows written', 'Skipped (already sent)'],
                [[$stats['imports'], $stats['rows'], $stats['skipped_sent']]],
            );
        }

        if ($this->option('link-companies') || (! $partnersOnly && ! $monthlyOnly)) {
            $this->info('Linking revenue partners to companies by phone…');
            $link = $seeder->linkPartnersToCompanies();
            $this->table(
                ['Linked', 'Already linked', 'No company match'],
                [[$link['linked'], $link['already'], $link['noMatch']]],
            );
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
