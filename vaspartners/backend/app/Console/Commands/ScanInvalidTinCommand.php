<?php

namespace App\Console\Commands;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Services\CompanyMembershipService;
use App\Support\TinNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScanInvalidTinCommand extends Command
{
    protected $signature = 'vas:scan-invalid-tin
                            {--dry-run : List matches without clearing approval or sending SMS}
                            {--no-sms : Clear false approvals without partner SMS / portal notification}
                            {--notify-all : Also SMS owners whose TIN is invalid but was never approved}
                            {--false-approvals-only : Only companies with tin_validated=true}
                            {--limit=0 : Max companies to process (0 = no limit)}
                            {--chunk=100 : Companies loaded per batch}';

    protected $description = 'Find companies with an owner and an invalid TIN; clear mistaken tin_validated and notify';

    public function handle(CompanyMembershipService $membership): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $notify = ! (bool) $this->option('no-sms');
        $notifyAll = (bool) $this->option('notify-all');
        $falseApprovalsOnly = (bool) $this->option('false-approvals-only');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $ownerRole = CompanyRole::Owner->value;

        $query = Company::query()
            ->whereHas('memberships', fn ($q) => $q->where('role', $ownerRole))
            ->when($falseApprovalsOnly, fn ($q) => $q->where('tin_validated', true))
            ->orderBy('id');

        $scanned = 0;
        $invalid = 0;
        $cleared = 0;
        $notified = 0;
        $skipped = 0;
        $errors = 0;

        $this->info(($dryRun ? '[dry-run] ' : '').'Scanning companies with owner for invalid TIN…');

        $query->chunkById($chunk, function ($companies) use (
            $membership,
            $dryRun,
            $notify,
            $notifyAll,
            $limit,
            &$scanned,
            &$invalid,
            &$cleared,
            &$notified,
            &$skipped,
            &$errors,
        ): bool {
            foreach ($companies as $company) {
                if ($limit > 0 && $invalid >= $limit) {
                    return false;
                }

                $scanned++;
                /** @var Company $company */
                if (TinNumber::isValid($company->tin)) {
                    continue;
                }

                $invalid++;
                $wasValidated = (bool) $company->tin_validated;
                $label = sprintf(
                    '%s | %s | tin=%s | validated=%s',
                    $company->public_id ?: 'id:'.$company->id,
                    $company->name ?: '—',
                    $company->tin ?: '(empty)',
                    $wasValidated ? 'yes' : 'no',
                );

                if ($dryRun) {
                    $this->line('  '.$label);

                    continue;
                }

                try {
                    if ($wasValidated) {
                        $result = $membership->clearInvalidTinApproval($company, $notify);
                        if ($result['cleared']) {
                            $cleared++;
                            $this->line('  cleared '.$label);
                        }
                        if (($result['notified'] ?? false) === true) {
                            $notified++;
                        }
                    } elseif ($notifyAll && $notify) {
                        $cacheKey = 'invalid-tin-sms:'.$company->id.':'.now()->format('Ymd');
                        if (Cache::has($cacheKey)) {
                            $skipped++;
                            $this->line('  skip already-sent-today '.$label);

                            continue;
                        }
                        $membership->notifyInvalidTin($company, false);
                        Cache::put($cacheKey, 1, now()->endOfDay());
                        $notified++;
                        $this->line('  notified '.$label);
                    } else {
                        $skipped++;
                        $this->line('  skipped '.$label.' (not approved; use --notify-all to SMS)');
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error('  error '.$company->public_id.': '.$e->getMessage());
                    Log::error('vas:scan-invalid-tin failed', [
                        'company_id' => $company->id,
                        'public_id' => $company->public_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return ! ($limit > 0 && $invalid >= $limit);
        });

        $summary = sprintf(
            'scanned=%d invalid=%d cleared=%d notified=%d skipped=%d errors=%d notify=%s',
            $scanned,
            $invalid,
            $cleared,
            $notified,
            $skipped,
            $errors,
            $notify && ! $dryRun ? 'yes' : 'no',
        );
        $this->info(($dryRun ? '[dry-run] ' : '').$summary);

        Log::info('vas:scan-invalid-tin', [
            'dry_run' => $dryRun,
            'notify' => $notify,
            'notify_all' => $notifyAll,
            'false_approvals_only' => $falseApprovalsOnly,
            'scanned' => $scanned,
            'invalid' => $invalid,
            'cleared' => $cleared,
            'notified' => $notified,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
