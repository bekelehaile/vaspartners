<?php

namespace App\Console\Commands;

use App\Enums\CompanyRole;
use App\Enums\ErcaNameStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Services\PartnerNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily SMS for companies that have a subscription and ERCA name mismatch.
 */
class NotifyErcaMismatchCommand extends Command
{
    protected $signature = 'vas:notify-erca-mismatch
                            {--dry-run : List matches without sending SMS}
                            {--force : Send even if already notified today}
                            {--limit=0 : Max companies to notify (0 = no limit)}
                            {--chunk=50 : Companies loaded per batch}';

    protected $description = 'SMS owners of subscribed companies whose name does not match ERCA legal name';

    public function handle(PartnerNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $alive = array_map(
            fn (SubscriptionStatus $s) => $s->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isAlive()),
        );
        $ownerRole = CompanyRole::Owner->value;

        $query = Company::query()
            ->where('erca_name_status', ErcaNameStatus::MismatchPending->value)
            ->whereHas('memberships', fn ($q) => $q->where('role', $ownerRole))
            ->whereHas('subscriptions', fn ($q) => $q->whereIn('status', $alive))
            ->orderBy('id');

        $scanned = 0;
        $notified = 0;
        $skipped = 0;
        $errors = 0;

        $this->info(($dryRun ? '[dry-run] ' : '').'Notifying ERCA mismatch companies with subscriptions…');

        $query->chunkById($chunk, function ($companies) use (
            $notifications,
            $dryRun,
            $force,
            $limit,
            &$scanned,
            &$notified,
            &$skipped,
            &$errors,
        ): bool {
            foreach ($companies as $company) {
                /** @var Company $company */
                if ($limit > 0 && $notified >= $limit) {
                    return false;
                }

                $scanned++;
                $cacheKey = 'erca:mismatch-sms:'.$company->id.':'.now()->format('Ymd');

                if (! $force && Cache::has($cacheKey)) {
                    $skipped++;
                    $this->line("skip already-sent-today {$company->public_id}");

                    continue;
                }

                $label = sprintf(
                    '%s | %s | tin=%s | legal=%s',
                    $company->public_id ?: 'id:'.$company->id,
                    $company->name ?: '—',
                    $company->tin ?: '—',
                    $company->legal_name ?: '—',
                );

                if ($dryRun) {
                    $notified++;
                    $this->line('[dry-run] '.$label);

                    continue;
                }

                try {
                    $notifications->companyErcaNameMismatch($company);
                    Cache::put($cacheKey, 1, now()->endOfDay());
                    $notified++;
                    $this->info('sent '.$label);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('vas:notify-erca-mismatch failed', [
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error('error '.$company->public_id.': '.$e->getMessage());
                }
            }

            return ! ($limit > 0 && $notified >= $limit);
        });

        $this->info("Done. scanned={$scanned} notified={$notified} skipped={$skipped} errors={$errors}");

        return self::SUCCESS;
    }
}
