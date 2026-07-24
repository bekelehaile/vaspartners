<?php

namespace App\Console\Commands;

use App\Services\Migration\MvasDumpClearService;
use Illuminate\Console\Command;

/**
 * Remove previously migrated MVAS dump rows so migration can be repeated.
 *
 * Example:
 *   php artisan vas:clear-mvas-migration --force
 *   php artisan vas:clear-mvas-migration --dry-run
 */
class ClearMvasMigrationCommand extends Command
{
    protected $signature = 'vas:clear-mvas-migration
        {--force : Required to actually delete (safety)}
        {--keep-approvers : Do not wipe service_final_approvers}
        {--keep-files : Do not delete attachment files from disk}
        {--dry-run : Count rows that would be removed}';

    protected $description = 'Clear migrated MVAS companies/customers/tickets/subscriptions/attachments so dump migration can be re-run';

    public function handle(MvasDumpClearService $clearer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force')) {
            $this->error('Refusing to clear without --force (or pass --dry-run).');

            return self::FAILURE;
        }

        $this->warn($dryRun
            ? 'Dry-run: counting migrated MVAS rows…'
            : 'Clearing migrated MVAS data…');

        $stats = $clearer->clear([
            'dry_run' => $dryRun,
            'clear_approvers' => ! (bool) $this->option('keep-approvers'),
            'clear_attachment_files' => ! (bool) $this->option('keep-files'),
        ]);

        $this->table(
            ['Entity', 'Count'],
            [
                ['ticket_documents', $stats['ticket_documents']],
                ['attachment_files_removed', $stats['attachment_files_removed']],
                ['tickets', $stats['tickets']],
                ['subscriptions', $stats['subscriptions']],
                ['memberships', $stats['memberships']],
                ['customers', $stats['customers']],
                ['companies', $stats['companies']],
                ['approvers', $stats['approvers']],
            ],
        );

        $this->info($dryRun ? 'Dry-run complete — nothing deleted.' : 'Clear complete. Safe to re-run vas:migrate-mvas-dump.');

        return self::SUCCESS;
    }
}
