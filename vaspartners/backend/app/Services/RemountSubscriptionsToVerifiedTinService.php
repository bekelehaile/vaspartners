<?php

namespace App\Services;

use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Subscription;
use App\Support\TinNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * After partners create a new ERCA-verified company, alive subscriptions often remain
 * on the abandoned MVAS placeholder company. Remount them onto the verified company.
 */
class RemountSubscriptionsToVerifiedTinService
{
    /**
     * @return array{moved: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function remountAll(bool $dryRun = false): array
    {
        $moved = 0;
        $skipped = 0;
        $rows = [];

        Company::query()
            ->where('tin_validated', true)
            ->where('erca_tin_verified', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($dryRun, &$moved, &$skipped, &$rows) {
                foreach ($companies as $company) {
                    if (! TinNumber::isValid($company->tin)) {
                        continue;
                    }
                    $result = $this->remountForCompany($company, $dryRun);
                    $moved += $result['moved'];
                    $skipped += $result['skipped'];
                    foreach ($result['rows'] as $row) {
                        $rows[] = $row;
                    }
                }
            });

        return compact('moved', 'skipped', 'rows');
    }

    /**
     * @return array{moved: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function remountForCompany(Company $verified, bool $dryRun = false): array
    {
        if (! $verified->tin_validated || ! $verified->erca_tin_verified || ! TinNumber::isValid($verified->tin)) {
            return ['moved' => 0, 'skipped' => 0, 'rows' => []];
        }

        $ownerContactIds = CompanyMembership::query()
            ->where('company_id', $verified->id)
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ownerContactIds === []) {
            return ['moved' => 0, 'skipped' => 0, 'rows' => []];
        }

        $memberContactIds = CompanyMembership::query()
            ->where('company_id', $verified->id)
            ->where('is_active', true)
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $alive = [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PendingRenewal->value,
            SubscriptionStatus::Grace->value,
        ];

        $candidates = Subscription::query()
            ->with(['company:id,name,tin,tin_validated', 'service:id,name'])
            ->whereIn('status', $alive)
            ->where('company_id', '!=', $verified->id)
            ->where(function ($q) use ($ownerContactIds, $memberContactIds) {
                $q->whereIn('contact_id', $ownerContactIds)
                    ->orWhereHas('activatedByTicket', fn ($t) => $t->whereIn('contact_id', $memberContactIds));
            })
            ->whereHas('company', function ($q) {
                $q->where(function ($inner) {
                    $inner->where('tin', 'like', 'MVAS-%')
                        ->orWhere('tin_validated', false)
                        ->orWhereNull('tin_validated');
                });
            })
            ->orderBy('id')
            ->get();

        $moved = 0;
        $skipped = 0;
        $rows = [];

        foreach ($candidates as $subscription) {
            $alreadyAlive = Subscription::query()
                ->where('company_id', $verified->id)
                ->where('service_id', $subscription->service_id)
                ->whereIn('status', $alive)
                ->where('id', '!=', $subscription->id)
                ->exists();

            $row = [
                'subscription_id' => $subscription->id,
                'service' => $subscription->service?->name,
                'from_company_id' => $subscription->company_id,
                'from_tin' => $subscription->company?->tin,
                'to_company_id' => $verified->id,
                'to_tin' => $verified->tin,
                'to_name' => $verified->name,
                'action' => $alreadyAlive ? 'skip_conflict' : ($dryRun ? 'would_move' : 'moved'),
            ];

            if ($alreadyAlive) {
                $skipped++;
                $rows[] = $row;

                continue;
            }

            if (! $dryRun) {
                DB::transaction(function () use ($subscription, $verified) {
                    $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();
                    if (! $locked) {
                        return;
                    }

                    $alive = [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PendingRenewal->value,
                        SubscriptionStatus::Grace->value,
                    ];
                    if (Subscription::query()
                        ->where('company_id', $verified->id)
                        ->where('service_id', $locked->service_id)
                        ->whereIn('status', $alive)
                        ->where('id', '!=', $locked->id)
                        ->exists()) {
                        return;
                    }

                    $fromId = (int) $locked->company_id;
                    $locked->forceFill(['company_id' => $verified->id])->save();

                    Log::info('Subscription remounted onto TIN-verified company', [
                        'subscription_id' => $locked->id,
                        'service_id' => $locked->service_id,
                        'from_company_id' => $fromId,
                        'to_company_id' => $verified->id,
                        'to_tin' => $verified->tin,
                    ]);
                });
            }

            $moved++;
            $rows[] = $row;
        }

        return compact('moved', 'skipped', 'rows');
    }
}
