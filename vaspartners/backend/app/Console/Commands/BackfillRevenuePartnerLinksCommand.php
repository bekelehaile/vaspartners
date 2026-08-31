<?php

namespace App\Console\Commands;

use App\Services\Migration\BackfillRevenuePartnerLinksService;
use Illuminate\Console\Command;

/**
 * Link all historical monthly revenue rows to Revenue Partners (service ID / short code).
 * Run once after Excel snapshot or CSV imports so partners see full history in the portal.
 */
class BackfillRevenuePartnerLinksCommand extends Command
{
    protected $signature = 'vas:backfill-revenue-partner-links
        {--dry-run : Report counts only; do not write}
        {--skip-companies : Do not link partners to portal companies by phone}';

    protected $description = 'Backfill revenue_import_rows.revenue_partner_id from partner master list (phone + amount history)';

    public function handle(BackfillRevenuePartnerLinksService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $linkCompanies = ! (bool) $this->option('skip-companies');

        if ($dryRun) {
            $this->warn('Dry run — no database changes.');
        }

        $stats = $backfill->run($dryRun, $linkCompanies);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows scanned', $stats['scanned']],
                ['Newly linked to partner', $stats['linked']],
                ['Already linked', $stats['already_linked']],
                ['Still unresolved (no master partner)', $stats['unresolved']],
                ['Row status upgraded (non-sent)', $stats['status_upgraded']],
                ['Imports status refreshed', $dryRun ? '—' : $stats['imports_refreshed']],
            ],
        );

        if ($stats['partners_company_linked'] !== null) {
            $link = $stats['partners_company_linked'];
            $this->info('Partner → company phone link:');
            $this->table(
                ['Linked', 'Already linked', 'No company match'],
                [[$link['linked'], $link['already'], $link['noMatch']]],
            );
        } elseif (! $dryRun && ! $this->option('skip-companies')) {
            $this->comment('Company linking skipped.');
        }

        if ($dryRun && $stats['linked'] > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
