<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Etrade\ErcaTinVerificationService;
use App\Services\Etrade\EtradeTinLookupService;
use App\Support\TinNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync companies.erca_phone from ERCA for TIN-verified companies.
 * Does not overwrite OTP or revenue phones.
 */
class SyncErcaPhonesCommand extends Command
{
    protected $signature = 'vas:sync-erca-phones
                            {--dry-run : Show what would change without writing}
                            {--force : Re-fetch even when erca_phone is already set}
                            {--limit= : Max companies this run (default all tin-validated)}
                            {--sleep-ms=400 : Pause between live lookups}';

    protected $description = 'Sync ERCA registry phone onto companies.erca_phone for TIN-verified companies';

    public function handle(EtradeTinLookupService $lookup, ErcaTinVerificationService $erca): int
    {
        if (! $lookup->enabled()) {
            $this->error('ERCA / eTrade lookup is disabled.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $query = Company::query()
            ->where('tin_validated', true)
            ->where('erca_tin_verified', true)
            ->whereRaw("tin ~ ?", ['^[0-9]{10}$'])
            ->when(
                ! $force,
                fn ($q) => $q->where(function ($inner): void {
                    $inner->whereNull('erca_phone')->orWhere('erca_phone', '');
                }),
            )
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $companies = $query->get(['id', 'public_id', 'name', 'tin', 'phone', 'otp_phone', 'erca_phone', 'revenue_phone']);
        $this->info(($dryRun ? '[dry-run] ' : '').'Syncing ERCA phones for '.$companies->count().' companies');

        $updated = 0;
        $unchanged = 0;
        $noMobile = 0;
        $notFound = 0;
        $errors = 0;
        $sameAsOtp = 0;
        $diffFromOtp = 0;

        foreach ($companies as $company) {
            /** @var Company $company */
            if (! TinNumber::isValid($company->tin)) {
                $this->line("skip invalid TIN {$company->public_id}");

                continue;
            }

            try {
                $result = $lookup->lookup((string) $company->tin);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('vas:sync-erca-phones lookup failed', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  {$company->public_id}: ".$e->getMessage());
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            if (! ($result['found'] ?? false)) {
                $notFound++;
                $this->line("  {$company->tin}: not found in ERCA");
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            $fields = $erca->contactFieldsFromLookup($result);
            $ercaPhone = $fields['erca_phone'] ?? null;
            if (! filled($ercaPhone)) {
                $noMobile++;
                $this->line("  {$company->tin}: ERCA has no usable mobile");
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            $otp = $company->otpPhone();
            $before = $company->ercaPhone();
            $same = $otp !== null && $otp === $ercaPhone;
            if ($same) {
                $sameAsOtp++;
            } else {
                $diffFromOtp++;
            }

            if ($before === $ercaPhone) {
                $unchanged++;
                $this->line(sprintf(
                    '  %s | erca=%s | otp=%s | already synced%s',
                    $company->tin,
                    $ercaPhone,
                    $otp ?: '—',
                    $same ? ' (matches OTP)' : ' (differs from OTP)',
                ));
            } else {
                $this->info(sprintf(
                    '  %s | %s → %s | otp=%s%s',
                    $company->tin,
                    $before ?: '(empty)',
                    $ercaPhone,
                    $otp ?: '—',
                    $same ? ' (matches OTP)' : ' (differs from OTP)',
                ));
                if (! $dryRun) {
                    $company->forceFill(['erca_phone' => $ercaPhone])->save();
                }
                $updated++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated', $updated],
                ['Already synced', $unchanged],
                ['ERCA no usable mobile', $noMobile],
                ['ERCA not found', $notFound],
                ['Errors', $errors],
                ['Among usable: matches OTP', $sameAsOtp],
                ['Among usable: differs from OTP', $diffFromOtp],
            ],
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
