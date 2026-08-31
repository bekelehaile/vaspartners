<?php

namespace App\Services\Migration;

use App\Enums\RevenueImportRowStatus;
use App\Models\Company;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Support\PartnerCompanyNameMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Fill revenue_partners.phone from linked portal companies (revenue / claim / company phone).
 * Optionally link partners to companies by name, then copy the phone.
 */
class BackfillRevenuePartnerPhonesFromCompaniesService
{
    /**
     * @return array{
     *   partners_scanned: int,
     *   phones_filled: int,
     *   phones_already_ok: int,
     *   phones_no_company_phone: int,
     *   companies_linked: int,
     *   companies_no_match: int,
     *   partners_with_company: int,
     *   rows_status_upgraded: int,
     *   imports_refreshed: int
     * }
     */
    public function run(bool $dryRun = false, bool $linkByName = true, bool $overwrite = false): array
    {
        $stats = [
            'partners_scanned' => 0,
            'phones_filled' => 0,
            'phones_already_ok' => 0,
            'phones_no_company_phone' => 0,
            'companies_linked' => 0,
            'companies_no_match' => 0,
            'partners_with_company' => 0,
            'rows_status_upgraded' => 0,
            'imports_refreshed' => 0,
        ];

        $filledPartnerIds = [];

        if ($linkByName) {
            $this->linkPartnersByCompanyName($dryRun, $stats);
        }

        RevenuePartner::query()
            ->where('is_active', true)
            ->with('company:id,name,phone,claim_phone,revenue_phone')
            ->orderBy('id')
            ->chunkById(200, function ($partners) use ($dryRun, $overwrite, &$stats, &$filledPartnerIds): void {
                foreach ($partners as $partner) {
                    $stats['partners_scanned']++;

                    if (! $partner->company_id || ! $partner->company) {
                        continue;
                    }

                    $companyPhone = $this->phoneFromCompany($partner->company);
                    if ($companyPhone === null) {
                        if (! $partner->hasUsablePhone()) {
                            $stats['phones_no_company_phone']++;
                        }

                        continue;
                    }

                    $current = PhoneNumber::normalizeNullable($partner->phone);
                    $hasUsable = $partner->hasUsablePhone();

                    if ($hasUsable && ! $overwrite) {
                        $stats['phones_already_ok']++;

                        continue;
                    }

                    if ($hasUsable && $current === $companyPhone) {
                        $stats['phones_already_ok']++;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['phones_filled']++;
                        $filledPartnerIds[(int) $partner->id] = true;

                        continue;
                    }

                    $partner->forceFill(['phone' => $companyPhone])->save();
                    $stats['phones_filled']++;
                    $filledPartnerIds[(int) $partner->id] = true;
                }
            });

        if ($dryRun) {
            $stats['rows_status_upgraded'] = $this->countUpgradableRows(array_keys($filledPartnerIds));
            $stats['partners_with_company'] = (int) RevenuePartner::query()
                ->where('is_active', true)
                ->whereNotNull('company_id')
                ->count();

            return $stats;
        }

        if ($filledPartnerIds !== []) {
            $stats['rows_status_upgraded'] = $this->upgradeRowStatuses(array_keys($filledPartnerIds), $stats);
        }

        $stats['partners_with_company'] = (int) RevenuePartner::query()
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->count();

        return $stats;
    }

    /**
     * Prefer revenue phone, then claim / company phone.
     */
    public function phoneFromCompany(Company $company): ?string
    {
        foreach ([
            PhoneNumber::normalizeNullable($company->revenue_phone),
            PhoneNumber::normalizeNullable($company->claim_phone),
            PhoneNumber::normalizeNullable($company->phone),
        ] as $phone) {
            if ($phone !== null && PhoneNumber::isValidLocalMobile($phone)) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $stats
     */
    protected function linkPartnersByCompanyName(bool $dryRun, array &$stats): void
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'claim_phone', 'revenue_phone']);

        if ($companies->isEmpty()) {
            return;
        }

        RevenuePartner::query()
            ->where('is_active', true)
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(200, function ($partners) use ($companies, $dryRun, &$stats): void {
                foreach ($partners as $partner) {
                    $matches = $companies->filter(
                        fn (Company $company) => PartnerCompanyNameMatcher::matches(
                            $partner->partner_name,
                            $company->name,
                        ),
                    )->values();

                    if ($matches->isEmpty()) {
                        $stats['companies_no_match']++;

                        continue;
                    }

                    /** @var Company $company */
                    $company = $matches->count() === 1
                        ? $matches->first()
                        : $matches->first(
                            fn (Company $candidate) => $this->phoneFromCompany($candidate) !== null,
                        ) ?? $matches->first();

                    if ($dryRun) {
                        $stats['companies_linked']++;

                        continue;
                    }

                    $partner->forceFill(['company_id' => $company->id])->save();
                    $stats['companies_linked']++;
                }
            });
    }

    /**
     * @param  list<int>  $partnerIds
     */
    protected function countUpgradableRows(array $partnerIds): int
    {
        if ($partnerIds === []) {
            return 0;
        }

        return RevenueImportRow::query()
            ->whereIn('revenue_partner_id', $partnerIds)
            ->where('status', RevenueImportRowStatus::MissingPhone->value)
            ->whereHas('partner', fn ($q) => $q->where('is_active', true))
            ->count();
    }

    /**
     * @param  list<int>  $partnerIds
     * @param  array<string, int>  $stats
     */
    protected function upgradeRowStatuses(array $partnerIds, array &$stats): int
    {
        $importIds = [];
        $upgraded = 0;

        RevenueImportRow::query()
            ->whereIn('revenue_partner_id', $partnerIds)
            ->where('status', RevenueImportRowStatus::MissingPhone->value)
            ->with('partner:id,phone,is_active')
            ->orderBy('id')
            ->chunkById(250, function ($rows) use (&$importIds, &$upgraded): void {
                foreach ($rows as $row) {
                    $partner = $row->partner;
                    if (! $partner?->hasUsablePhone()) {
                        continue;
                    }

                    if ($row->wasSent()) {
                        continue;
                    }

                    $row->forceFill([
                        'status' => RevenueImportRowStatus::Matched,
                        'error' => null,
                    ])->save();

                    $importIds[(int) $row->revenue_import_id] = true;
                    $upgraded++;
                }
            });

        DB::transaction(function () use ($importIds, &$stats): void {
            foreach (array_keys($importIds) as $importId) {
                $import = RevenueImport::query()->find($importId);
                if ($import) {
                    $import->resolveStatusFromRows();
                    $stats['imports_refreshed']++;
                }
            }
        });

        return $upgraded;
    }
}
