<?php

namespace App\Console\Commands;

use App\Services\Migration\MvasDumpCommentImportService;
use Illuminate\Console\Command;

class MigrateMvasCommentsCommand extends Command
{
    protected $signature = 'vas:migrate-mvas-comments
        {--dump= : Absolute path to MVAS MySQL .dump (or MVAS_DUMP_PATH)}
        {--limit= : Max comments to import}
        {--dry-run : Report without writing}';

    protected $description = 'Import MVAS ticket comments / messages into ticket_comments';

    public function handle(MvasDumpCommentImportService $importer): int
    {
        $dump = (string) ($this->option('dump') ?: env('MVAS_DUMP_PATH') ?: '');
        if ($dump === '') {
            $dump = '/mvas-dumps/mvas_20260729_134749.dump';
        }
        if (! is_file($dump)) {
            $this->error('Dump not found: '.$dump);

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '[dry-run] ' : '').'Importing MVAS comments from '.$dump);

        $stats = $importer->import([
            'dump' => $dump,
            'dry_run' => $dryRun,
            'limit' => $limit !== null && $limit !== '' ? (int) $limit : null,
        ]);

        foreach ($stats as $key => $value) {
            $this->line(sprintf('  %-22s %s', $key, $value));
        }

        return self::SUCCESS;
    }
}
