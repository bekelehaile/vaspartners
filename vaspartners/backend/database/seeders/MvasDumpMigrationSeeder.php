<?php

namespace Database\Seeders;

use App\Services\Migration\MvasDumpMigrationService;
use Illuminate\Database\Seeder;

/**
 * Seed companies, customers, and tickets from an MVAS MySQL `.dump`.
 *
 * Env:
 *   MVAS_DUMP_PATH=/path/to/mvas_YYYYMMDD_HHMMSS.dump  (required unless --dump passed via artisan)
 *   MVAS_SEED_COMPANY_LIMIT= (optional int)
 *   MVAS_SEED_TICKET_LIMIT= (optional int)
 *   MVAS_SEED_DRY_RUN=false
 *   MVAS_SEED_ONLY_VERIFIED=false
 *   MVAS_SEED_LINK_MEMBERSHIPS=true
 *
 * Prefer the artisan command for interactive runs:
 *   php artisan vas:seed-mvas-dump --dump=/tmp/mvas.dump
 */
class MvasDumpMigrationSeeder extends Seeder
{
    public function run(MvasDumpMigrationService $migration): void
    {
        $dump = (string) (
            env('MVAS_DUMP_PATH')
            ?: config('services.mvas.dump_path')
            ?: ''
        );

        if ($dump === '' || ! is_file($dump)) {
            $this->command?->error(
                'Set MVAS_DUMP_PATH to a readable MVAS .dump file (or use: php artisan vas:seed-mvas-dump --dump=...).'
            );

            return;
        }

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

        $this->command?->table(
            ['Entity', 'Selected', 'Imported', 'Skipped', 'Other'],
            [
                [
                    'companies',
                    $stats['companies']['selected'],
                    $stats['companies']['imported'],
                    $stats['companies']['skipped'],
                    '',
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
                    'orphaned='.$stats['tickets']['orphaned'],
                ],
                [
                    'memberships',
                    '',
                    $stats['memberships']['linked'],
                    $stats['memberships']['skipped'],
                    '',
                ],
            ],
        );
    }
}
