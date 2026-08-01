<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Services\CompanyPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remove companies that lack an ERCA-validated TIN and have no alive subscriptions.
 * Exclusive orphan contacts belonging only to those companies are removed by CompanyPurgeService.
 */
class PurgeCompaniesWithoutValidTinCommand extends Command
{
    protected $signature = 'vas:purge-companies-without-valid-tin
                            {--dry-run : List matching companies without deleting}
                            {--chunk=50 : Process batch size}';

    protected $description = 'Purge companies without a valid (ERCA-verified) TIN and with no active/pending/grace subscription';

    public function handle(CompanyPurgeService $purge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $alive = array_map(
            fn (SubscriptionStatus $s) => $s->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isAlive()),
        );

        $query = Company::query()
            ->where(function ($q): void {
                $q->where('erca_tin_verified', false)
                    ->orWhereNull('tin')
                    ->orWhere('tin', '')
                    ->orWhereRaw("length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g')) <> 10");
            })
            ->whereDoesntHave('subscriptions', function ($q) use ($alive): void {
                $q->whereIn('status', $alive);
            })
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info(($dryRun ? '[dry-run] ' : '')."Found {$total} company(ies) without valid TIN and no alive subscription.");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $purged = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById($chunk, function ($companies) use ($purge, $dryRun, &$purged, &$skipped, &$failed): void {
            foreach ($companies as $company) {
                /** @var Company $company */
                if ($company->isTinValidated()) {
                    $skipped++;

                    continue;
                }

                if ($company->isForcePurgeProtected() || ! $purge->canForcePurge($company)) {
                    $this->warn("Skip protected #{$company->id} {$company->name}");
                    $skipped++;

                    continue;
                }

                $label = trim((string) ($company->name ?: $company->public_id))." (#{$company->id}, tin=".($company->tin ?: '—').')';

                if ($dryRun) {
                    $this->line("Would purge: {$label}");
                    $purged++;

                    continue;
                }

                try {
                    $stats = $purge->forcePurge($company);
                    $this->info("Purged: {$label} (contacts={$stats['contacts']}, tickets={$stats['tickets']})");
                    $purged++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Failed #{$company->id}: {$e->getMessage()}");
                    report($e);
                }
            }
        });

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Done. matched={$total} purged={$purged} skipped={$skipped} failed={$failed}");

        Log::info('vas:purge-companies-without-valid-tin', [
            'dry_run' => $dryRun,
            'matched' => $total,
            'purged' => $purged,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
