<?php

namespace App\Console\Commands;

use App\Services\Migration\ImportExistingAggregatorPartnersService;
use Illuminate\Console\Command;

/**
 * Import legacy master-aggregator partners (Service ID + company name + short code).
 * TIN / owner profile is completed later during portal TIN verification.
 *
 *   php artisan vas:import-existing-aggregator-partners --dry-run
 *   php artisan vas:import-existing-aggregator-partners --link-companies
 */
class ImportExistingAggregatorPartnersCommand extends Command
{
    protected $signature = 'vas:import-existing-aggregator-partners
        {--path= : JSON snapshot path (default database/data/revenue/existing_aggregator_partners.json)}
        {--dry-run : Count creates/updates without writing}
        {--link-companies : Link to portal companies by unique name match}
        {--no-overwrite-phone : Keep existing phones when already set}';

    protected $description = 'Import existing master-aggregator partners into Revenue Partners (unique Service ID; Product ID / SPID / Short Code / phone)';

    public function handle(ImportExistingAggregatorPartnersService $importer): int
    {
        $path = trim((string) $this->option('path'));
        if ($path === '') {
            $path = ImportExistingAggregatorPartnersService::DEFAULT_FILE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $link = (bool) $this->option('link-companies');
        $overwritePhone = ! (bool) $this->option('no-overwrite-phone');

        $this->info(($dryRun ? '[dry-run] ' : '').'Importing existing aggregator partners…');
        if ($link) {
            $this->line('Company linking by unique name match is ON.');
        }

        try {
            $stats = $importer->import($path, $dryRun, $link, $overwritePhone);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Total', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Phones set', 'Phones invalid', 'Linked', 'Already linked', 'No company'],
            [[
                $stats['total'],
                $stats['created'],
                $stats['updated'],
                $stats['unchanged'],
                $stats['skipped'],
                $stats['phones_set'],
                $stats['phones_invalid'],
                $stats['linked'],
                $stats['already_linked'],
                $stats['no_company_match'],
            ]],
        );

        if ($stats['skipped_keys'] !== []) {
            $this->warn('Skipped keys: '.implode(', ', array_slice($stats['skipped_keys'], 0, 20)));
        }

        $this->info($dryRun ? 'Dry-run complete — no rows written.' : 'Import complete.');

        return self::SUCCESS;
    }
}
