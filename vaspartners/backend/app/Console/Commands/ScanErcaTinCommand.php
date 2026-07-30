<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Etrade\ErcaTinVerificationService;
use App\Support\TinNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic ERCA / eTrade TIN re-check — deliberately small batches with sleep.
 * Never floods upstream: default --limit=10 and global per-minute cap in the service.
 */
class ScanErcaTinCommand extends Command
{
    protected $signature = 'vas:scan-erca-tin
                            {--dry-run : List due companies without calling ERCA}
                            {--force : Bypass per-TIN cache / next_check (still rate-limited globally)}
                            {--limit= : Max companies this run (default from ETRADE_SCHEDULE_LIMIT)}
                            {--sleep-ms= : Pause between companies (default ETRADE_SCHEDULE_SLEEP_MS)}';

    protected $description = 'Re-check due company TINs against ERCA in small throttled batches';

    public function handle(ErcaTinVerificationService $erca): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) ($this->option('limit') ?: config('services.etrade.schedule_limit', 10)));
        $sleepMs = max(0, (int) ($this->option('sleep-ms') ?: config('services.etrade.schedule_sleep_ms', 1500)));

        $query = Company::query()
            ->whereNotNull('tin')
            ->where('tin', '!=', '')
            ->where(function ($q): void {
                $q->whereNull('erca_next_check_at')
                    ->orWhere('erca_next_check_at', '<=', now());
            })
            ->orderByRaw('erca_next_check_at IS NULL DESC')
            ->orderBy('erca_next_check_at')
            ->orderBy('id')
            ->limit($limit);

        $companies = $query->get();
        $this->info(($dryRun ? '[dry-run] ' : '').'ERCA scan: '.$companies->count().' due (limit '.$limit.')');

        $checked = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($companies as $company) {
            /** @var Company $company */
            if (! TinNumber::isValid($company->tin)) {
                $skipped++;
                $this->line("skip invalid TIN {$company->public_id}");

                continue;
            }

            $this->line(sprintf(
                '%s | %s | tin=%s | status=%s',
                $company->public_id ?: 'id:'.$company->id,
                $company->name ?: '—',
                $company->tin,
                (string) ($company->erca_name_status?->value ?? $company->erca_name_status ?: 'unchecked'),
            ));

            if ($dryRun) {
                $checked++;

                continue;
            }

            try {
                $result = $erca->verifyCompany($company, force: $force);
                $checked++;
                $this->info('  → '.$result['status'].($result['needs_consent'] ? ' (needs consent)' : ''));
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('vas:scan-erca-tin failed', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error('  → '.$e->getMessage());
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Done. checked={$checked} skipped={$skipped} errors={$errors}");

        return self::SUCCESS;
    }
}
