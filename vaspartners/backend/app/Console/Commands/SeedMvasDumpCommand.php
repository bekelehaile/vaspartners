<?php

namespace App\Console\Commands;

use App\Services\Migration\MvasDumpEnrichmentService;
use App\Services\Migration\MvasDumpMigrationService;
use Illuminate\Console\Command;

/**
 * Seed/migrate from an MVAS MySQL `.dump` into VAS Partners format.
 *
 * Example:
 *   php artisan vas:seed-mvas-dump --dump=/tmp/mvas.dump --storage=/mvas-storage
 */
class SeedMvasDumpCommand extends Command
{
    protected $signature = 'vas:seed-mvas-dump
        {--dump= : Absolute path to MVAS MySQL .dump file (or MVAS_DUMP_PATH env)}
        {--storage= : Path to old MVAS storage/app (attachments; or MVAS_STORAGE_PATH)}
        {--company-limit= : Max clients/companies/customers to import (omit = all)}
        {--ticket-limit= : Max tickets to import (omit = all matching clients)}
        {--attachment-limit= : Max ticket attachments to import}
        {--ids= : Comma-separated legacy clients.id allowlist}
        {--only-verified : Only clients with is_verified_client=1}
        {--include-ethio-telecom : Include company_name ethio telecom}
        {--skip-companies : Do not create/update companies}
        {--skip-customers : Do not create/update customers}
        {--skip-tickets : Do not import tickets}
        {--skip-subscriptions : Do not derive subscriptions / manage links}
        {--skip-approvers : Do not import service final approvers}
        {--skip-attachments : Do not copy ticket attachments}
        {--only-enrich : Skip companies/customers/tickets; only subs/approvers/attachments}
        {--link-memberships : Also link each customer as company owner (off by default)}
        {--dry-run : Parse and report without writing}';

    protected $description = 'Migrate MVAS dump → companies, customers, tickets, subscriptions, approvers, attachments';

    public function handle(
        MvasDumpMigrationService $migration,
        MvasDumpEnrichmentService $enrichment,
    ): int {
        $dump = (string) ($this->option('dump') ?: env('MVAS_DUMP_PATH') ?: '');
        if ($dump === '') {
            $this->error('Pass --dump=/path/to/mvas_YYYYMMDD_HHMMSS.dump (or set MVAS_DUMP_PATH).');

            return self::FAILURE;
        }

        if (! is_file($dump)) {
            $this->error('Dump file not found: '.$dump);

            return self::FAILURE;
        }

        $storage = (string) ($this->option('storage') ?: env('MVAS_STORAGE_PATH') ?: '');
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->option('ids')),
        )));

        $companyLimit = $this->option('company-limit');
        $ticketLimit = $this->option('ticket-limit');
        $attachmentLimit = $this->option('attachment-limit');
        $onlyEnrich = (bool) $this->option('only-enrich');

        $this->info('Source dump: '.$dump);
        if ($storage !== '') {
            $this->info('MVAS storage: '.$storage);
        }
        $this->info($this->option('dry-run') ? 'Mode: dry-run' : 'Mode: seed');

        $stats = [
            'companies' => ['selected' => 0, 'imported' => 0, 'skipped' => 0],
            'customers' => ['selected' => 0, 'imported' => 0, 'skipped' => 0],
            'tickets' => ['selected' => 0, 'imported' => 0, 'skipped' => 0, 'orphaned' => 0],
            'memberships' => ['linked' => 0, 'skipped' => 0],
        ];

        if (! $onlyEnrich) {
            $stats = $migration->migrate([
                'dump' => $dump,
                'company_limit' => $companyLimit !== null && $companyLimit !== '' ? (int) $companyLimit : null,
                'ticket_limit' => $ticketLimit !== null && $ticketLimit !== '' ? (int) $ticketLimit : null,
                'dry_run' => (bool) $this->option('dry-run'),
                'only_verified' => (bool) $this->option('only-verified'),
                'include_ethio_telecom' => (bool) $this->option('include-ethio-telecom'),
                'client_ids' => $ids,
                'skip_companies' => (bool) $this->option('skip-companies'),
                'skip_customers' => (bool) $this->option('skip-customers'),
                'skip_tickets' => (bool) $this->option('skip-tickets'),
                'link_memberships' => (bool) $this->option('link-memberships'),
            ]);
        }

        $enrichStats = $enrichment->enrich([
            'dump' => $dump,
            'storage' => $storage !== '' ? $storage : null,
            'dry_run' => (bool) $this->option('dry-run'),
            'skip_subscriptions' => (bool) $this->option('skip-subscriptions'),
            'skip_approvers' => (bool) $this->option('skip-approvers'),
            'skip_attachments' => (bool) $this->option('skip-attachments'),
            'attachment_limit' => $attachmentLimit !== null && $attachmentLimit !== ''
                ? (int) $attachmentLimit
                : null,
        ]);

        $this->table(
            ['Entity', 'Selected', 'Imported', 'Skipped', 'Notes'],
            [
                [
                    'companies',
                    $stats['companies']['selected'],
                    $stats['companies']['imported'],
                    $stats['companies']['skipped'],
                    $onlyEnrich ? 'skipped (--only-enrich)' : '',
                ],
                [
                    'customers',
                    $stats['customers']['selected'],
                    $stats['customers']['imported'],
                    $stats['customers']['skipped'],
                    '',
                ],
                [
                    'tickets',
                    $stats['tickets']['selected'],
                    $stats['tickets']['imported'],
                    $stats['tickets']['skipped'],
                    'orphaned '.$stats['tickets']['orphaned'],
                ],
                [
                    'memberships',
                    '—',
                    $stats['memberships']['linked'],
                    $stats['memberships']['skipped'],
                    '',
                ],
                [
                    'subscriptions',
                    '—',
                    $enrichStats['subscriptions']['imported'],
                    $enrichStats['subscriptions']['skipped'],
                    'terminated '.$enrichStats['subscriptions']['terminated']
                        .' · linked tickets '.$enrichStats['subscriptions']['linked_tickets'],
                ],
                [
                    'approvers',
                    '—',
                    $enrichStats['approvers']['imported'],
                    $enrichStats['approvers']['skipped'],
                    '',
                ],
                [
                    'attachments',
                    '—',
                    $enrichStats['attachments']['imported'],
                    $enrichStats['attachments']['skipped'],
                    'missing '.$enrichStats['attachments']['missing_file']
                        .' · unmapped '.$enrichStats['attachments']['unmapped_type']
                        .' · orphaned '.$enrichStats['attachments']['orphaned']
                        .(isset($enrichStats['attachments']['note']) ? ' · '.$enrichStats['attachments']['note'] : ''),
                ],
            ],
        );

        $this->line('Notes:');
        $this->line('  • Companies stay ownerless until Fayda phone-claim or admin Assign owner.');
        $this->line('  • Subscriptions derived from completed New-subscription tickets; manage tickets linked.');
        $this->line('  • Approvers from dump service_approvers → service_final_approvers.');
        $this->line('  • Attachments copied from old MVAS storage/app into tickets/{public_id}/.');

        return self::SUCCESS;
    }
}
