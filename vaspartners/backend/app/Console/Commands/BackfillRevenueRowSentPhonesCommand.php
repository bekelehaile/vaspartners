<?php

namespace App\Console\Commands;

use App\Services\Migration\BackfillRevenueRowSentPhonesService;
use Illuminate\Console\Command;

class BackfillRevenueRowSentPhonesCommand extends Command
{
    protected $signature = 'vas:backfill-revenue-row-sent-phones
        {--dry-run : Report only}
        {--all-rows : Fill every revenue row from SMS / partner / company (historical migration)}';

    protected $description = 'Backfill revenue_import_rows.sent_phone from SMS logs or partner/company phone';

    public function handle(BackfillRevenueRowSentPhonesService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allRows = (bool) $this->option('all-rows');

        if ($dryRun) {
            $this->warn('Dry run — no database changes.');
        }

        $stats = $backfill->run($dryRun, $allRows);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows scanned', $stats['scanned']],
                ['Already had sent_phone', $stats['already_set']],
                ['Filled from SMS recipient', $stats['from_sms']],
                ['Filled from partner phone', $stats['from_partner']],
                ['Filled from legacy CSV (consolidated partners)', $stats['from_legacy_csv']],
                ['Filled from company phone', $stats['from_company']],
                ['Filled from unique partner name match', $stats['from_partner_name']],
                ['Still missing phone', $stats['still_missing']],
            ],
        );

        if ($dryRun && ($stats['from_sms'] + $stats['from_partner'] + $stats['from_legacy_csv'] + $stats['from_company'] + $stats['from_partner_name']) > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
