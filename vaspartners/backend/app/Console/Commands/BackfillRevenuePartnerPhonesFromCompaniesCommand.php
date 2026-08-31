<?php

namespace App\Console\Commands;

use App\Services\Migration\BackfillRevenuePartnerPhonesFromCompaniesService;
use Illuminate\Console\Command;

/**
 * Copy portal company phones onto revenue partners (revenue_phone → claim → company phone).
 * Run after MVAS / company migration so historical revenue can SMS and show in the portal.
 */
class BackfillRevenuePartnerPhonesFromCompaniesCommand extends Command
{
    protected $signature = 'vas:backfill-revenue-partner-phones-from-companies
        {--dry-run : Report counts only; do not write}
        {--no-link-by-name : Skip linking unlinked partners to companies by name}
        {--overwrite : Replace existing partner phones with company phone}';

    protected $description = 'Backfill revenue_partners.phone from linked portal companies';

    public function handle(BackfillRevenuePartnerPhonesFromCompaniesService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no database changes.');
        }

        $stats = $backfill->run(
            $dryRun,
            ! (bool) $this->option('no-link-by-name'),
            (bool) $this->option('overwrite'),
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Partners scanned (with company link step)', $stats['partners_scanned']],
                ['Companies linked to partner (by name)', $stats['companies_linked']],
                ['Partners linked to a company (after run)', $stats['partners_with_company']],
                ['Unlinked partners (no name match)', $stats['companies_no_match']],
                ['Phones filled from company', $stats['phones_filled']],
                ['Phones already OK on partner', $stats['phones_already_ok']],
                ['Linked company has no usable phone', $stats['phones_no_company_phone']],
                ['Import rows upgraded (missing phone → ready)', $dryRun ? $stats['rows_status_upgraded'].' (est.)' : $stats['rows_status_upgraded']],
                ['Imports status refreshed', $dryRun ? '—' : $stats['imports_refreshed']],
            ],
        );

        if ($dryRun && ($stats['phones_filled'] > 0 || $stats['companies_linked'] > 0)) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
