<?php

namespace Database\Seeders;

use App\Services\Migration\MvasDumpEnrichmentService;
use App\Services\Migration\MvasDumpMigrationService;
use Illuminate\Database\Seeder;

/**
 * Optional seeder wrapper around the MVAS dump migrate pipeline.
 *
 * Prefer artisan:
 *   php artisan vas:migrate-mvas-dump --fresh --force --dump=... --storage=...
 *
 * Env:
 *   MVAS_DUMP_PATH, MVAS_STORAGE_PATH
 *   MVAS_SEED_COMPANY_LIMIT, MVAS_SEED_TICKET_LIMIT, MVAS_SEED_DRY_RUN
 *   MVAS_SEED_ONLY_VERIFIED, MVAS_SEED_INCLUDE_ETHIO, MVAS_SEED_LINK_MEMBERSHIPS
 *   MVAS_SEED_SKIP_ATTACHMENTS, MVAS_SEED_SKIP_APPROVERS, MVAS_SEED_SKIP_SUBSCRIPTIONS
 */
class MvasDumpMigrationSeeder extends Seeder
{
    public function run(
        MvasDumpMigrationService $migration,
        MvasDumpEnrichmentService $enrichment,
    ): void {
        $dump = (string) (env('MVAS_DUMP_PATH') ?: '');
        if ($dump === '' || ! is_file($dump)) {
            $this->command?->error('Set MVAS_DUMP_PATH to a readable MVAS .dump (or use vas:migrate-mvas-dump).');

            return;
        }

        $storage = (string) (env('MVAS_STORAGE_PATH') ?: '');
        $companyLimit = env('MVAS_SEED_COMPANY_LIMIT');
        $ticketLimit = env('MVAS_SEED_TICKET_LIMIT');

        $this->command?->info('Seeding from dump: '.$dump);

        $stats = $migration->migrate([
            'dump' => $dump,
            'company_limit' => $companyLimit !== null && $companyLimit !== '' ? (int) $companyLimit : null,
            'ticket_limit' => $ticketLimit !== null && $ticketLimit !== '' ? (int) $ticketLimit : null,
            'dry_run' => filter_var(env('MVAS_SEED_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
            'only_verified' => filter_var(env('MVAS_SEED_ONLY_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
            'include_ethio_telecom' => filter_var(env('MVAS_SEED_INCLUDE_ETHIO', false), FILTER_VALIDATE_BOOLEAN),
            'link_memberships' => filter_var(env('MVAS_SEED_LINK_MEMBERSHIPS', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $enrichStats = $enrichment->enrich([
            'dump' => $dump,
            'storage' => $storage !== '' ? $storage : null,
            'dry_run' => filter_var(env('MVAS_SEED_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
            'skip_subscriptions' => filter_var(env('MVAS_SEED_SKIP_SUBSCRIPTIONS', false), FILTER_VALIDATE_BOOLEAN),
            'skip_approvers' => filter_var(env('MVAS_SEED_SKIP_APPROVERS', false), FILTER_VALIDATE_BOOLEAN),
            'skip_attachments' => filter_var(env('MVAS_SEED_SKIP_ATTACHMENTS', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $this->command?->table(
            ['Entity', 'Imported', 'Skipped'],
            [
                ['companies', $stats['companies']['imported'], $stats['companies']['skipped']],
                ['customers', $stats['customers']['imported'], $stats['customers']['skipped']],
                ['tickets', $stats['tickets']['imported'], $stats['tickets']['skipped']],
                ['subscriptions', $enrichStats['subscriptions']['imported'], $enrichStats['subscriptions']['skipped']],
                ['approvers', $enrichStats['approvers']['imported'], $enrichStats['approvers']['skipped']],
                ['attachments', $enrichStats['attachments']['imported'], $enrichStats['attachments']['skipped']],
            ],
        );
    }
}
