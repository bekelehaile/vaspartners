<?php

namespace App\Services\Etrade;

use App\Enums\ErcaNameStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Support\TinNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Apply ERCA / eTrade TIN verification to a company, with name-mismatch consent.
 *
 * Hard rate limits:
 * - per-TIN cache lock (default 6h) — no repeat upstream calls
 * - global per-minute cap for scheduled scans
 */
class ErcaTinVerificationService
{
    public function __construct(
        protected EtradeTinLookupService $lookup,
    ) {}

    /**
     * @return array{
     *   company: Company,
     *   found: bool,
     *   name_matched: bool,
     *   needs_consent: bool,
     *   legal_name: ?string,
     *   entered_name: string,
     *   status: string
     * }
     */
    public function verifyCompany(Company $company, bool $force = false): array
    {
        if (! TinNumber::isValid($company->tin)) {
            throw ValidationException::withMessages([
                'tin' => TinNumber::message(),
            ]);
        }

        $tin = TinNumber::normalize($company->tin);

        if (! $force && ! $this->shouldCallUpstream($company, $tin)) {
            return $this->snapshot($company);
        }

        if (! $this->lookup->enabled()) {
            $company->forceFill([
                'erca_name_status' => ErcaNameStatus::Failed->value,
                'erca_last_checked_at' => now(),
                'erca_next_check_at' => now()->addHours($this->retryHours()),
                'erca_last_error' => 'ERCA lookup disabled',
            ])->save();

            return $this->snapshot($company->fresh() ?? $company);
        }

        if (! $this->acquireGlobalSlot()) {
            $company->forceFill([
                'erca_next_check_at' => now()->addMinutes(15),
                'erca_last_error' => 'Rate limited — retry later',
            ])->save();

            if ($force) {
                throw ValidationException::withMessages([
                    'company_tin' => 'TIN verification is busy right now. Please try again in a few minutes.',
                ]);
            }

            return $this->snapshot($company->fresh() ?? $company);
        }

        $result = $this->lookup->lookup($tin);
        $this->rememberTinLookup($tin);

        $entered = (string) ($company->name ?: '');
        $legal = $result['legal_name'] ?? $result['business_name'] ?? null;

        if (! empty($result['raw']['unavailable'])) {
            $company->forceFill([
                'erca_name_status' => ErcaNameStatus::Failed->value,
                'erca_tin_verified' => false,
                'erca_last_checked_at' => now(),
                'erca_next_check_at' => now()->addHours($this->retryHours()),
                'erca_last_error' => 'Upstream unavailable',
            ])->save();

            return $this->snapshot($company->fresh() ?? $company);
        }

        if (! $result['found'] || ! filled($legal)) {
            $company->forceFill([
                'legal_name' => null,
                'erca_tin_verified' => false,
                'erca_verified_at' => null,
                'erca_name_status' => ErcaNameStatus::NotFound->value,
                'erca_last_checked_at' => now(),
                'erca_next_check_at' => now()->addHours($this->recheckHours()),
                'erca_last_error' => null,
            ])->save();

            return $this->snapshot($company->fresh() ?? $company);
        }

        $matched = CompanyNameMatcher::matches($entered, (string) $legal);
        $previous = ErcaNameStatus::tryFrom((string) $company->erca_name_status);

        // Preserve partner consent if they already resolved a mismatch for the same legal name.
        $status = ErcaNameStatus::Matched;
        if (! $matched) {
            if (
                $previous === ErcaNameStatus::AcceptedLegal
                && CompanyNameMatcher::matches((string) $company->legal_name, (string) $legal)
            ) {
                $status = ErcaNameStatus::AcceptedLegal;
            } elseif (
                $previous === ErcaNameStatus::KeptBoth
                && CompanyNameMatcher::matches((string) $company->legal_name, (string) $legal)
            ) {
                $status = ErcaNameStatus::KeptBoth;
            } else {
                $status = ErcaNameStatus::MismatchPending;
            }
        }

        $company->forceFill([
            'legal_name' => $legal,
            'erca_tin_verified' => true,
            'erca_verified_at' => now(),
            'erca_name_status' => $status->value,
            'erca_last_checked_at' => now(),
            'erca_next_check_at' => now()->addHours($this->recheckHours()),
            'erca_last_error' => null,
        ])->save();

        return $this->snapshot($company->fresh() ?? $company);
    }

    /**
     * Re-evaluate name match using stored legal_name (no upstream call).
     */
    public function rematchEnteredName(Company $company): Company
    {
        if (! $company->erca_tin_verified || ! filled($company->legal_name)) {
            return $company;
        }

        $matched = CompanyNameMatcher::matches((string) $company->name, (string) $company->legal_name);
        $previous = ErcaNameStatus::tryFrom((string) $company->erca_name_status);

        if ($matched) {
            $company->forceFill([
                'erca_name_status' => ErcaNameStatus::Matched->value,
                'erca_last_error' => null,
            ])->save();

            return $company->fresh() ?? $company;
        }

        if (in_array($previous, [ErcaNameStatus::AcceptedLegal, ErcaNameStatus::KeptBoth], true)) {
            return $company;
        }

        $company->forceFill([
            'erca_name_status' => ErcaNameStatus::MismatchPending->value,
        ])->save();

        return $company->fresh() ?? $company;
    }

    /**
     * Partner consent after name mismatch.
     *
     * @param  'use_legal'|'keep_both'  $action
     */
    public function applyNameConsent(Company $company, Contact $actor, string $action): Company
    {
        $status = ErcaNameStatus::tryFrom((string) $company->erca_name_status);
        if ($status !== ErcaNameStatus::MismatchPending) {
            throw ValidationException::withMessages([
                'consent' => 'No pending legal-name consent for this company.',
            ]);
        }

        if (! filled($company->legal_name)) {
            throw ValidationException::withMessages([
                'consent' => 'Legal name is missing. Re-check the TIN first.',
            ]);
        }

        if ($action === 'use_legal') {
            $company->forceFill([
                'name' => $company->legal_name,
                'erca_name_status' => ErcaNameStatus::AcceptedLegal->value,
            ])->save();
        } elseif ($action === 'keep_both') {
            $company->forceFill([
                'erca_name_status' => ErcaNameStatus::KeptBoth->value,
            ])->save();
        } else {
            throw ValidationException::withMessages([
                'consent' => 'Choose use_legal or keep_both.',
            ]);
        }

        Log::info('ERCA name consent applied', [
            'company_id' => $company->id,
            'contact_id' => $actor->id,
            'action' => $action,
            'legal_name' => $company->legal_name,
            'name' => $company->name,
        ]);

        return $company->fresh() ?? $company;
    }

    /**
     * @return array{
     *   company: Company,
     *   found: bool,
     *   name_matched: bool,
     *   needs_consent: bool,
     *   legal_name: ?string,
     *   entered_name: string,
     *   status: string
     * }
     */
    public function snapshot(Company $company): array
    {
        $status = ErcaNameStatus::tryFrom((string) ($company->erca_name_status ?: 'unchecked'))
            ?? ErcaNameStatus::Unchecked;

        return [
            'company' => $company,
            'found' => (bool) $company->erca_tin_verified,
            'name_matched' => $status === ErcaNameStatus::Matched
                || $status === ErcaNameStatus::AcceptedLegal,
            'needs_consent' => $status->needsPartnerConsent(),
            'legal_name' => $company->legal_name,
            'entered_name' => (string) $company->name,
            'status' => $status->value,
        ];
    }

    protected function shouldCallUpstream(Company $company, string $tin): bool
    {
        if (Cache::has($this->tinCacheKey($tin))) {
            return false;
        }

        if ($company->erca_next_check_at && $company->erca_next_check_at->isFuture()) {
            return false;
        }

        return true;
    }

    protected function rememberTinLookup(string $tin): void
    {
        $hours = max(1, (int) config('services.etrade.tin_cache_hours', 6));
        Cache::put($this->tinCacheKey($tin), 1, now()->addHours($hours));
    }

    protected function tinCacheKey(string $tin): string
    {
        return 'etrade:tin-lookup:'.$tin;
    }

    protected function acquireGlobalSlot(): bool
    {
        $perMinute = max(1, (int) config('services.etrade.global_lookups_per_minute', 8));
        $key = 'etrade:global-lookups:'.now()->format('YmdHi');
        $count = (int) Cache::get($key, 0);
        if ($count >= $perMinute) {
            return false;
        }
        Cache::put($key, $count + 1, now()->addMinutes(2));

        return true;
    }

    protected function recheckHours(): int
    {
        return max(24, (int) config('services.etrade.recheck_hours', 168)); // default 7 days
    }

    protected function retryHours(): int
    {
        return max(1, (int) config('services.etrade.retry_hours', 6));
    }
}
