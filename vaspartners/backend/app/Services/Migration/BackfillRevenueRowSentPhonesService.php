<?php

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Services\RevenuePartnerResolver;
use App\Support\PartnerCompanyNameMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Backfill revenue_import_rows.sent_phone — permissive for legacy data.
 * Does not require partner link or company link; uses any available phone source.
 */
class BackfillRevenueRowSentPhonesService
{
    /** @var Collection<int, Company>|null */
    protected ?Collection $companies = null;

    /** @var array{byExact: array<string, string>, byCore: array<string, string>, byShort: array<string, string>}|null */
    protected ?array $partnerPhoneIndex = null;

    /** @var array{byExact: array<string, string>, byCore: array<string, string>, byShort: array<string, string>}|null */
    protected ?array $csvPhoneIndex = null;

    /**
     * @return array{
     *   scanned: int,
     *   from_sms: int,
     *   from_partner: int,
     *   from_legacy_csv: int,
     *   from_company: int,
     *   from_partner_name: int,
     *   already_set: int,
     *   still_missing: int
     * }
     */
    public function run(bool $dryRun = false, bool $allRows = false): array
    {
        $stats = [
            'scanned' => 0,
            'from_sms' => 0,
            'from_partner' => 0,
            'from_legacy_csv' => 0,
            'from_company' => 0,
            'from_partner_name' => 0,
            'already_set' => 0,
            'still_missing' => 0,
        ];

        $this->companies = Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'claim_phone', 'revenue_phone']);

        $this->partnerPhoneIndex = $this->buildPartnerPhoneIndex();
        $this->csvPhoneIndex = $this->buildConsolidatedCsvIndex();

        $query = RevenueImportRow::query()
            ->with([
                'smsRecipient:id,phone_normalized,phone_raw',
                'partner:id,phone,company_id,partner_name',
                'partner.company:id,phone,claim_phone,revenue_phone',
            ])
            ->orderBy('id');

        if (! $allRows) {
            $query->where(function ($q): void {
                $q->where('status', 'sent')
                    ->orWhereNotNull('sent_at')
                    ->orWhereNotNull('bulk_message_recipient_id');
            });
        }

        $query->chunkById(250, function ($rows) use ($dryRun, &$stats): void {
            foreach ($rows as $row) {
                $stats['scanned']++;

                if ($this->hasStoredPhone($row->sent_phone)) {
                    $stats['already_set']++;

                    continue;
                }

                if ((float) ($row->amount ?? 0) <= 0) {
                    $stats['still_missing']++;

                    continue;
                }

                [$phone, $source] = $this->resolvePhone($row);

                if ($phone === null) {
                    $stats['still_missing']++;

                    continue;
                }

                match ($source) {
                    'sms' => $stats['from_sms']++,
                    'partner', 'partner_lookup' => $stats['from_partner']++,
                    'legacy_csv' => $stats['from_legacy_csv']++,
                    'company' => $stats['from_company']++,
                    'partner_name' => $stats['from_partner_name']++,
                    default => null,
                };

                if ($dryRun) {
                    continue;
                }

                DB::table('revenue_import_rows')
                    ->where('id', $row->id)
                    ->update(['sent_phone' => $phone, 'updated_at' => now()]);
            }
        });

        $this->companies = null;
        $this->partnerPhoneIndex = null;
        $this->csvPhoneIndex = null;

        return $stats;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolvePhone(RevenueImportRow $row): array
    {
        $smsPhone = $this->phoneFromSmsRecipient($row);
        if ($smsPhone !== null) {
            return [$smsPhone, 'sms'];
        }

        $linkedPhone = PhoneNumber::normalizeNullable($row->partner?->phone);
        if ($linkedPhone !== null && $this->isUsablePhone($linkedPhone)) {
            return [$linkedPhone, 'partner'];
        }

        $lookupPhone = $this->phoneFromPartnerIndex(
            RevenuePartnerResolver::normalize($row->service_id),
            RevenuePartnerResolver::normalize($row->short_code),
        );
        if ($lookupPhone !== null) {
            return [$lookupPhone, 'partner_lookup'];
        }

        $csvPhone = $this->phoneFromPartnerIndex(
            RevenuePartnerResolver::normalize($row->service_id),
            RevenuePartnerResolver::normalize($row->short_code),
            $this->csvPhoneIndex,
        );
        if ($csvPhone !== null) {
            return [$csvPhone, 'legacy_csv'];
        }

        $company = $this->findCompanyForRow($row);
        $companyPhone = $this->phoneFromCompany($company);
        if ($companyPhone !== null) {
            return [$companyPhone, 'company'];
        }

        $namePhone = $this->phoneFromUniquePartnerName($row->partner_name);
        if ($namePhone !== null) {
            return [$namePhone, 'partner_name'];
        }

        return [null, null];
    }

    protected function phoneFromUniquePartnerName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || str_starts_with($name, 'Partner ')) {
            return null;
        }

        $matches = RevenuePartner::query()
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['partner_name', 'phone'])
            ->filter(fn (RevenuePartner $partner) => PartnerCompanyNameMatcher::matches(
                $name,
                $partner->partner_name,
            ))
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $phone = PhoneNumber::normalizeNullable($matches->first()->phone);

        return $phone !== null && $this->isUsablePhone($phone) ? $phone : null;
    }

    /**
     * @return array{byExact: array<string, string>, byCore: array<string, string>, byShort: array<string, string>}
     */
    protected function buildPartnerPhoneIndex(): array
    {
        $byExact = [];
        $byCore = [];
        $byShort = [];

        RevenuePartner::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->get(['service_id', 'short_code', 'phone'])
            ->each(function (RevenuePartner $partner) use (&$byExact, &$byCore, &$byShort): void {
                $phone = PhoneNumber::normalizeNullable($partner->phone);
                if ($phone === null || ! $this->isUsablePhone($phone)) {
                    return;
                }

                $serviceId = RevenuePartnerResolver::normalize($partner->service_id);
                if ($serviceId !== null) {
                    $byExact[$serviceId] ??= $phone;
                    if (preg_match('/^\d{10,}$/', $serviceId)) {
                        $core = ltrim($serviceId, '0') ?: '0';
                        $byCore[$core] ??= $phone;
                    }
                }

                $shortCode = RevenuePartnerResolver::normalize($partner->short_code);
                if ($shortCode !== null) {
                    $byShort[$shortCode] ??= $phone;
                }
            });

        return compact('byExact', 'byCore', 'byShort');
    }

    /**
     * @return array{byExact: array<string, string>, byCore: array<string, string>, byShort: array<string, string>}
     */
    protected function buildConsolidatedCsvIndex(): array
    {
        $byExact = [];
        $byCore = [];
        $byShort = [];

        $path = database_path('data/consolidated_partners.csv');
        if (! File::isFile($path)) {
            return compact('byExact', 'byCore', 'byShort');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return compact('byExact', 'byCore', 'byShort');
        }

        fgetcsv($handle, escape: '\\'); // header

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $serviceId = RevenuePartnerResolver::normalize($row[0] ?? null);
            $shortCode = RevenuePartnerResolver::normalize($row[1] ?? null);
            $phone = PhoneNumber::normalizeNullable($row[6] ?? null);

            if ($phone === null || ! $this->isUsablePhone($phone)) {
                continue;
            }

            if ($serviceId !== null) {
                $byExact[$serviceId] ??= $phone;
                if (preg_match('/^\d{10,}$/', $serviceId)) {
                    $core = ltrim($serviceId, '0') ?: '0';
                    $byCore[$core] ??= $phone;
                }
            }

            if ($shortCode !== null) {
                $byShort[$shortCode] ??= $phone;
            }
        }

        fclose($handle);

        return compact('byExact', 'byCore', 'byShort');
    }

    /**
     * @param  array{byExact: array<string, string>, byCore: array<string, string>, byShort: array<string, string>}|null  $index
     */
    protected function phoneFromPartnerIndex(?string $serviceId, ?string $shortCode, ?array $index = null): ?string
    {
        $index ??= $this->partnerPhoneIndex;
        if ($index === null) {
            return null;
        }

        if ($serviceId !== null && isset($index['byExact'][$serviceId])) {
            return $index['byExact'][$serviceId];
        }

        if ($serviceId !== null && preg_match('/^\d{10,}$/', $serviceId)) {
            $core = ltrim($serviceId, '0') ?: '0';
            if (isset($index['byCore'][$core])) {
                return $index['byCore'][$core];
            }
        }

        if ($shortCode !== null && isset($index['byShort'][$shortCode])) {
            return $index['byShort'][$shortCode];
        }

        return null;
    }

    protected function findCompanyForRow(RevenueImportRow $row): ?Company
    {
        $linked = $row->partner?->company;
        if ($linked && $this->phoneFromCompany($linked) !== null) {
            return $linked;
        }

        foreach ([
            $row->partner?->partner_name,
            $row->partner_name,
        ] as $name) {
            $company = $this->findCompanyByName($name, strict: true);
            if ($company !== null) {
                return $company;
            }

            $company = $this->findCompanyByName($name, strict: false);
            if ($company !== null) {
                return $company;
            }
        }

        return $linked;
    }

    protected function findCompanyByName(?string $name, bool $strict): ?Company
    {
        $name = trim((string) $name);
        if ($name === '' || str_starts_with($name, 'Partner ')) {
            return null;
        }

        $companies = $this->companies ?? collect();

        if ($strict) {
            $matches = $companies->filter(
                fn (Company $company) => PartnerCompanyNameMatcher::matches($name, $company->name),
            )->values();
        } else {
            $normalized = PartnerCompanyNameMatcher::normalize($name);
            if ($normalized === '' || strlen($normalized) < 4) {
                return null;
            }

            $matches = $companies->filter(function (Company $company) use ($normalized): bool {
                $companyNorm = PartnerCompanyNameMatcher::normalize($company->name);

                return $companyNorm !== ''
                    && (str_contains($companyNorm, $normalized) || str_contains($normalized, $companyNorm));
            })->values();
        }

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        return $matches->first(
            fn (Company $company) => $this->phoneFromCompany($company) !== null,
        ) ?? $matches->first();
    }

    protected function phoneFromSmsRecipient(RevenueImportRow $row): ?string
    {
        $recipient = $row->smsRecipient;
        if (! $recipient) {
            return null;
        }

        $phone = PhoneNumber::normalizeNullable($recipient->phone_normalized)
            ?: PhoneNumber::normalizeNullable($recipient->phone_raw);

        return $phone !== '' ? $phone : null;
    }

    protected function phoneFromCompany(?Company $company): ?string
    {
        if (! $company) {
            return null;
        }

        foreach ([
            PhoneNumber::normalizeNullable($company->revenue_phone),
            PhoneNumber::normalizeNullable($company->claim_phone),
            PhoneNumber::normalizeNullable($company->phone),
        ] as $phone) {
            if ($phone !== null && $this->isUsablePhone($phone)) {
                return $phone;
            }
        }

        return null;
    }

    protected function hasStoredPhone(mixed $phone): bool
    {
        $normalized = PhoneNumber::normalizeNullable($phone);

        return $normalized !== null && $normalized !== '';
    }

    protected function isUsablePhone(string $phone): bool
    {
        if (PhoneNumber::isValidLocalMobile($phone)) {
            return true;
        }

        return (bool) preg_match('/^\d{9}$/', $phone);
    }
}
