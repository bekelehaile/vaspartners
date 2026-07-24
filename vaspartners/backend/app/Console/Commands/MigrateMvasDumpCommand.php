<?php

namespace App\Console\Commands;

use App\Services\Migration\MvasDumpClearService;
use App\Services\Migration\MvasDumpEnrichmentService;
use App\Services\Migration\MvasDumpMigrationService;
use Illuminate\Console\Command;

/**
 * Repeatable MVAS → VAS Partners migration pipeline.
 *
 *   # wipe previous import then full migrate
 *   php artisan vas:migrate-mvas-dump --fresh --force \
 *     --dump=/mvas-dumps/mvas_20260724_090806.dump \
 *     --storage=/mvas-storage
 *
 *   # re-run without wipe (idempotent skips)
 *   php artisan vas:migrate-mvas-dump --dump=... --storage=...
 */
class MigrateMvasDumpCommand extends Command
{
    protected $signature = 'vas:migrate-mvas-dump
        {--dump= : Absolute path to MVAS MySQL .dump (or MVAS_DUMP_PATH)}
        {--storage= : Old MVAS storage/app path (or MVAS_STORAGE_PATH)}
        {--fresh : Clear previous migrated data before import}
        {--force : Required with --fresh}
        {--keep-approvers : When clearing, keep service_final_approvers}
        {--company-limit= : Max clients to import}
        {--ticket-limit= : Max tickets to import}
        {--attachment-limit= : Max attachments to import}
        {--ids= : Comma-separated legacy clients.id allowlist}
        {--only-verified : Only verified clients}
        {--include-ethio-telecom : Include ethio telecom company rows}
        {--skip-attachments : Skip attachment copy}
        {--skip-approvers : Skip final-approver import}
        {--skip-subscriptions : Skip subscription derivation}
        {--link-memberships : Link customers as owners during seed (default off)}
        {--dry-run : Report without writing}';

    protected $description = 'Clear (optional) + seed + enrich MVAS dump into VAS Partners format';

    public function handle(
        MvasDumpClearService $clearer,
        MvasDumpMigrationService $migration,
        MvasDumpEnrichmentService $enrichment,
    ): int {
        $dump = (string) ($this->option('dump') ?: env('MVAS_DUMP_PATH') ?: '');
        if ($dump === '') {
            $dump = '/mvas-dumps/mvas_20260724_090806.dump';
        }
        if (! is_file($dump)) {
            $this->error('Dump not found: '.$dump);

            return self::FAILURE;
        }

        $storage = (string) ($this->option('storage') ?: env('MVAS_STORAGE_PATH') ?: '');
        if ($storage === '' && is_dir('/mvas-storage')) {
            $storage = '/mvas-storage';
        }

        $dryRun = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');

        if ($fresh) {
            if (! $dryRun && ! $this->option('force')) {
                $this->error('--fresh requires --force (or use --dry-run).');

                return self::FAILURE;
            }

            $this->warn($dryRun ? '[1/3] Clear (dry-run)' : '[1/3] Clear previous MVAS migration');
            $clearStats = $clearer->clear([
                'dry_run' => $dryRun,
                'clear_approvers' => ! (bool) $this->option('keep-approvers'),
                'clear_attachment_files' => true,
            ]);
            $this->line(sprintf(
                '  companies=%d customers=%d tickets=%d subs=%d docs=%d approvers=%d',
                $clearStats['companies'],
                $clearStats['customers'],
                $clearStats['tickets'],
                $clearStats['subscriptions'],
                $clearStats['ticket_documents'],
                $clearStats['approvers'],
            ));
        } else {
            $this->line('[1/3] Clear skipped (pass --fresh --force to wipe first)');
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->option('ids')),
        )));
        $companyLimit = $this->option('company-limit');
        $ticketLimit = $this->option('ticket-limit');
        $attachmentLimit = $this->option('attachment-limit');

        $this->info($dryRun ? '[2/3] Seed (dry-run)' : '[2/3] Seed companies / customers / tickets');
        $stats = $migration->migrate([
            'dump' => $dump,
            'company_limit' => $companyLimit !== null && $companyLimit !== '' ? (int) $companyLimit : null,
            'ticket_limit' => $ticketLimit !== null && $ticketLimit !== '' ? (int) $ticketLimit : null,
            'dry_run' => $dryRun,
            'only_verified' => (bool) $this->option('only-verified'),
            'include_ethio_telecom' => (bool) $this->option('include-ethio-telecom'),
            'client_ids' => $ids,
            'link_memberships' => (bool) $this->option('link-memberships'),
        ]);

        $this->info($dryRun ? '[3/3] Enrich (dry-run)' : '[3/3] Enrich subscriptions / approvers / attachments');
        $enrichStats = $enrichment->enrich([
            'dump' => $dump,
            'storage' => $storage !== '' ? $storage : null,
            'dry_run' => $dryRun,
            'skip_subscriptions' => (bool) $this->option('skip-subscriptions'),
            'skip_approvers' => (bool) $this->option('skip-approvers'),
            'skip_attachments' => (bool) $this->option('skip-attachments'),
            'attachment_limit' => $attachmentLimit !== null && $attachmentLimit !== ''
                ? (int) $attachmentLimit
                : null,
        ]);

        $this->table(
            ['Entity', 'Imported', 'Skipped', 'Notes'],
            [
                ['companies', $stats['companies']['imported'], $stats['companies']['skipped'], 'selected '.$stats['companies']['selected']],
                ['customers', $stats['customers']['imported'], $stats['customers']['skipped'], ''],
                ['tickets', $stats['tickets']['imported'], $stats['tickets']['skipped'], 'orphaned '.$stats['tickets']['orphaned']],
                ['subscriptions', $enrichStats['subscriptions']['imported'], $enrichStats['subscriptions']['skipped'], 'linked '.$enrichStats['subscriptions']['linked_tickets']],
                ['approvers', $enrichStats['approvers']['imported'], $enrichStats['approvers']['skipped'], ''],
                ['attachments', $enrichStats['attachments']['imported'], $enrichStats['attachments']['skipped'], 'missing '.$enrichStats['attachments']['missing_file']],
            ],
        );

        $this->line('Repeat anytime:');
        $this->line('  php artisan vas:migrate-mvas-dump --fresh --force --dump='.$dump.($storage !== '' ? ' --storage='.$storage : ''));

        return self::SUCCESS;
    }
}
