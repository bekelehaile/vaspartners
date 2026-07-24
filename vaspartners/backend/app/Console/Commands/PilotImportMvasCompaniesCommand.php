<?php

namespace App\Console\Commands;

use App\Services\Migration\PilotMvasCompanyImporter;
use Illuminate\Console\Command;

/**
 * Pilot demo: import a small set of MVAS partner companies from a MySQL `.dump`.
 *
 * Example:
 *   php artisan vas:pilot-import-mvas-companies \
 *     --dump=/data/mvas_20260724_090806.dump \
 *     --limit=25
 */
class PilotImportMvasCompaniesCommand extends Command
{
    protected $signature = 'vas:pilot-import-mvas-companies
        {--dump= : Absolute path to MVAS MySQL .dump file (required)}
        {--limit=25 : Max companies to import}
        {--ids= : Comma-separated legacy clients.id allowlist}
        {--include-ethio-telecom : Include rows whose company_name is ethio telecom}
        {--all-clients : Do not require is_verified_client=1}
        {--dry-run : Parse and report without writing}';

    protected $description = 'Pilot-import ownerless approved companies from an MVAS .dump for Fayda phone-claim demos';

    public function handle(PilotMvasCompanyImporter $importer): int
    {
        $dump = (string) $this->option('dump');
        if ($dump === '') {
            $this->error('Pass --dump=/path/to/mvas_YYYYMMDD_HHMMSS.dump');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->option('ids')),
        )));

        $this->info('Source dump: '.$dump);
        $this->info($this->option('dry-run') ? 'Mode: dry-run' : 'Mode: import');

        $result = $importer->import([
            'dump' => $dump,
            'limit' => (int) $this->option('limit'),
            'dry_run' => (bool) $this->option('dry-run'),
            'include_ethio_telecom' => (bool) $this->option('include-ethio-telecom'),
            'only_verified' => ! (bool) $this->option('all-clients'),
            'ids' => $ids,
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Selected from dump', $result['selected']],
                ['Imported / would import', $result['imported']],
                ['Skipped (already exists)', $result['skipped']],
            ],
        );

        if ($result['companies'] !== []) {
            $rows = array_map(function (array $row): array {
                return [
                    $row['action'] ?? '',
                    (string) ($row['legacy_mvas_client_id'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['tin'] ?? ''),
                    (string) ($row['reason'] ?? ''),
                ];
            }, array_slice($result['companies'], 0, 40));

            $this->table(
                ['Action', 'Legacy ID', 'Phone', 'Company', 'TIN', 'Reason'],
                $rows,
            );
        }

        $this->line('Demo next steps:');
        $this->line('  1) Companies are approved + active + ownerless.');
        $this->line('  2) Fayda login whose phone last-9 matches company.phone auto-claims ownership.');
        $this->line('  3) Non-matching Fayda users must submit a company profile for admin approval.');

        return self::SUCCESS;
    }
}
