<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\PartnerNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SMS partners whose subscription contract renewal_date is due within configured offsets.
 *
 * Uses subscription.contract_signed_at / renewal_date (contract follow-up fields),
 * not service period next_renewal_due_at (that opens renewal tickets via vas:open-due-renewals).
 */
class NotifyContractRenewalsCommand extends Command
{
    protected $signature = 'vas:notify-contract-renewals
                            {--dry-run : List matches without sending SMS}
                            {--force : Send even if already notified for this milestone}
                            {--limit=0 : Max subscriptions to notify (0 = no limit)}
                            {--chunk=50 : Subscriptions loaded per batch}
                            {--days= : Comma-separated days-before offsets (overrides config)}';

    protected $description = 'SMS partners when subscription renewal_date hits reminder offsets (contract date / renewal date)';

    public function handle(PartnerNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $offsets = $this->resolveOffsets();
        if ($offsets === []) {
            $this->warn('No reminder offsets configured (CONTRACT_RENEWAL_SMS_DAYS_BEFORE / --days).');

            return self::SUCCESS;
        }

        $alive = array_map(
            fn (SubscriptionStatus $s) => $s->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isAlive()),
        );

        $today = now()->startOfDay();
        $targetDates = [];
        foreach ($offsets as $daysBefore) {
            $targetDates[$daysBefore] = $today->copy()->addDays($daysBefore)->toDateString();
        }
        $uniqueDates = array_values(array_unique(array_values($targetDates)));

        $query = Subscription::query()
            ->with(['contact', 'company', 'service'])
            ->whereIn('status', $alive)
            ->whereNotNull('renewal_date')
            ->whereIn('renewal_date', $uniqueDates)
            ->orderBy('id');

        $scanned = 0;
        $notified = 0;
        $skipped = 0;
        $errors = 0;

        $offsetList = implode(',', $offsets);
        $this->info(($dryRun ? '[dry-run] ' : '')."Notifying contract renewals (offsets={$offsetList} days before)…");

        $query->chunkById($chunk, function ($subscriptions) use (
            $notifications,
            $dryRun,
            $force,
            $limit,
            $targetDates,
            &$scanned,
            &$notified,
            &$skipped,
            &$errors,
        ): bool {
            foreach ($subscriptions as $subscription) {
                /** @var Subscription $subscription */
                if ($limit > 0 && $notified >= $limit) {
                    return false;
                }

                $scanned++;
                $renewalDate = $subscription->renewal_date?->toDateString();
                if ($renewalDate === null) {
                    $skipped++;

                    continue;
                }

                $daysRemaining = null;
                foreach ($targetDates as $daysBefore => $date) {
                    if ($date === $renewalDate) {
                        $daysRemaining = (int) $daysBefore;
                        break;
                    }
                }

                if ($daysRemaining === null) {
                    $skipped++;

                    continue;
                }

                $cacheKey = sprintf(
                    'subscription:renewal-sms:%d:%s:%d',
                    $subscription->id,
                    $renewalDate,
                    $daysRemaining,
                );

                if (! $force && Cache::has($cacheKey)) {
                    $skipped++;
                    $this->line("skip already-sent {$subscription->public_id} days={$daysRemaining}");

                    continue;
                }

                $label = sprintf(
                    '%s | %s | service=%s | renewal=%s | days=%d | signed=%s',
                    $subscription->public_id ?: 'id:'.$subscription->id,
                    $subscription->company?->name ?: '—',
                    $subscription->service?->name ?: '—',
                    $renewalDate,
                    $daysRemaining,
                    $subscription->contract_signed_at?->toDateString() ?: '—',
                );

                if ($dryRun) {
                    $notified++;
                    $this->line('[dry-run] '.$label);

                    continue;
                }

                try {
                    $notifications->subscriptionRenewalReminder($subscription, $daysRemaining);
                    // Keep until a bit after the renewal day so --force is needed to re-send a milestone.
                    Cache::put($cacheKey, 1, now()->addDays(max(2, $daysRemaining + 2)));
                    $notified++;
                    if ($notified <= 5 || $notified % 50 === 0) {
                        $this->info('queued '.$label);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('vas:notify-contract-renewals failed', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error('error '.$subscription->public_id.': '.$e->getMessage());
                }
            }

            return ! ($limit > 0 && $notified >= $limit);
        });

        $this->info("Done. scanned={$scanned} notified={$notified} skipped={$skipped} errors={$errors}");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    protected function resolveOffsets(): array
    {
        $raw = $this->option('days');
        if (is_string($raw) && trim($raw) !== '') {
            $parts = array_map(
                static fn (string $d): int => (int) trim($d),
                explode(',', $raw),
            );
        } else {
            $parts = config('vas.contract_renewal_sms_days_before', [30, 7, 0]);
            if (! is_array($parts)) {
                $parts = [30, 7, 0];
            }
            $parts = array_map(static fn ($d): int => (int) $d, $parts);
        }

        $parts = array_values(array_unique(array_filter(
            $parts,
            static fn (int $d): bool => $d >= 0,
        )));
        sort($parts);

        return $parts;
    }
}
