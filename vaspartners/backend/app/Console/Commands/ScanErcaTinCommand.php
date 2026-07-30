<?php

namespace App\Console\Commands;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Services\Etrade\ErcaTinVerificationService;
use App\Support\TinNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic ERCA / eTrade TIN re-check — deliberately small batches with sleep.
 * Defaults to companies that Have owner so orphan TINs are not flooded.
 */
class ScanErcaTinCommand extends Command
{
    protected $signature = 'vas:scan-erca-tin
                            {--dry-run : List due companies without calling ERCA}
                            {--force : Bypass per-TIN cache / next_check (still rate-limited globally)}
                            {--include-ownerless : Also include companies with no owner}
                            {--unverified-only : Only companies not yet ERCA verified (erca_tin_verified=false)}
                            {--limit= : Max companies this run (default from ETRADE_SCHEDULE_LIMIT)}
                            {--sleep-ms= : Pause between companies (default ETRADE_SCHEDULE_SLEEP_MS)}';

    protected $description = 'Re-check due company TINs against ERCA in small throttled batches (Has owner by default)';

    public function handle(ErcaTinVerificationService $erca): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $ownersOnly = ! (bool) $this->option('include-ownerless');
        $unverifiedOnly = (bool) $this->option('unverified-only');
        $limit = max(1, (int) ($this->option('limit') ?: config('services.etrade.schedule_limit', 10)));
        $sleepMs = max(0, (int) ($this->option('sleep-ms') ?: config('services.etrade.schedule_sleep_ms', 1500)));

        $ownerRole = CompanyRole::Owner->value;

        $query = Company::query()
            ->whereNotNull('tin')
            ->where('tin', '!=', '')
            // Only valid 10-digit TINs — skip format-invalid rows so they do not consume the batch.
            ->whereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) = 10")
            ->when(
                $ownersOnly,
                fn ($q) => $q->whereHas(
                    'memberships',
                    fn ($m) => $m->where('role', $ownerRole),
                ),
            )
            ->when($unverifiedOnly, fn ($q) => $q->where('erca_tin_verified', false))
            ->where(function ($q): void {
                $q->whereNull('erca_next_check_at')
                    ->orWhere('erca_next_check_at', '<=', now());
            })
            ->orderByRaw('erca_next_check_at IS NULL DESC')
            ->orderBy('erca_next_check_at')
            ->orderBy('id')
            ->limit($limit);

        $companies = $query->get();
        $scope = ($ownersOnly ? 'Has owner' : 'all').($unverifiedOnly ? ', unverified only' : '');
        $this->info(($dryRun ? '[dry-run] ' : '').'ERCA scan: '.$companies->count().' due (limit '.$limit.', '.$scope.')');

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

            $statusLabel = $company->erca_name_status instanceof \App\Enums\ErcaNameStatus
                ? $company->erca_name_status->value
                : (is_string($company->erca_name_status) ? $company->erca_name_status : 'unchecked');

            $this->line(sprintf(
                '%s | %s | tin=%s | status=%s | verified=%s | owner=%s',
                $company->public_id ?: 'id:'.$company->id,
                $company->name ?: '—',
                $company->tin,
                $statusLabel,
                $company->erca_tin_verified ? 'yes' : 'no',
                $company->hasOwner() ? 'yes' : 'no',
            ));

            if ($dryRun) {
                $checked++;

                continue;
            }

            try {
                $result = $erca->verifyCompany($company, force: $force);
                $checked++;
                $fresh = $result['company'];
                $this->info(sprintf(
                    '  → %s%s | name=%s | legal=%s | verified=%s',
                    $result['status'],
                    $result['needs_consent'] ? ' (needs consent)' : '',
                    $fresh->name ?: '—',
                    $fresh->legal_name ?: '—',
                    $fresh->erca_tin_verified ? 'yes' : 'no',
                ));
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
