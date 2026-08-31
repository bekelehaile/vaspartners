<?php

namespace App\Services\Migration;

use App\Enums\RevenueImportRowStatus;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Services\RevenuePartnerResolver;
use Illuminate\Support\Facades\DB;

/**
 * Link historical revenue_import_rows to revenue_partners so each partner
 * (and portal company) can see all migrated monthly amounts.
 */
class BackfillRevenuePartnerLinksService
{
    /**
     * @return array{
     *   scanned: int,
     *   linked: int,
     *   already_linked: int,
     *   unresolved: int,
     *   status_upgraded: int,
     *   imports_refreshed: int,
     *   partners_company_linked: array{linked: int, already: int, no_match: int}|null
     * }
     */
    public function run(bool $dryRun = false, bool $linkCompanies = true): array
    {
        $index = $this->buildPartnerIndex();
        $stats = [
            'scanned' => 0,
            'linked' => 0,
            'already_linked' => 0,
            'unresolved' => 0,
            'status_upgraded' => 0,
            'imports_refreshed' => 0,
            'partners_company_linked' => null,
        ];

        $importIds = [];

        RevenueImportRow::query()
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($index, $dryRun, &$stats, &$importIds): void {
                foreach ($rows as $row) {
                    $stats['scanned']++;

                    if ($row->revenue_partner_id !== null) {
                        $stats['already_linked']++;

                        continue;
                    }

                    $partner = $this->findPartner(
                        RevenuePartnerResolver::normalize($row->service_id),
                        RevenuePartnerResolver::normalize($row->short_code),
                        $index,
                    );

                    if (! $partner) {
                        $stats['unresolved']++;

                        continue;
                    }

                    $importIds[(int) $row->revenue_import_id] = true;
                    $stats['linked']++;

                    if ($dryRun) {
                        continue;
                    }

                    $wasSent = $row->wasSent();
                    $previousStatus = $row->status;
                    $payload = $this->rowPayloadForPartner($row, $partner, $wasSent);

                    $row->forceFill($payload)->save();

                    if (! $wasSent && $previousStatus !== $payload['status']) {
                        $stats['status_upgraded']++;
                    }
                }
            });

        if ($dryRun) {
            return $stats;
        }

        DB::transaction(function () use ($importIds, &$stats): void {
            foreach (array_keys($importIds) as $importId) {
                $import = RevenueImport::query()->find($importId);
                if ($import) {
                    $import->resolveStatusFromRows();
                    $stats['imports_refreshed']++;
                }
            }
        });

        if ($linkCompanies) {
            $stats['partners_company_linked'] = app(SeedRevenueExcelSnapshotService::class)
                ->linkPartnersToCompanies();
        }

        return $stats;
    }

    /**
     * @return array{byExact: array<string, RevenuePartner>, byCore: array<string, RevenuePartner>, byShort: array<string, RevenuePartner>}
     */
    protected function buildPartnerIndex(): array
    {
        $byExact = [];
        $byCore = [];
        $byShort = [];

        RevenuePartner::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'service_id', 'short_code', 'partner_name', 'phone', 'is_active'])
            ->each(function (RevenuePartner $partner) use (&$byExact, &$byCore, &$byShort): void {
                $serviceId = RevenuePartnerResolver::normalize($partner->service_id);
                if ($serviceId !== null) {
                    $byExact[$serviceId] ??= $partner;
                    if (preg_match('/^\d{10,}$/', $serviceId)) {
                        $core = ltrim($serviceId, '0') ?: '0';
                        $byCore[$core] ??= $partner;
                    }
                }

                $shortCode = RevenuePartnerResolver::normalize($partner->short_code);
                if ($shortCode !== null) {
                    $byShort[$shortCode] ??= $partner;
                }
            });

        return compact('byExact', 'byCore', 'byShort');
    }

    /**
     * @param  array{byExact: array<string, RevenuePartner>, byCore: array<string, RevenuePartner>, byShort: array<string, RevenuePartner>}  $index
     */
    protected function findPartner(?string $serviceId, ?string $shortCode, array $index): ?RevenuePartner
    {
        if ($serviceId !== null) {
            if (isset($index['byExact'][$serviceId])) {
                return $index['byExact'][$serviceId];
            }

            if (preg_match('/^\d{10,}$/', $serviceId)) {
                $core = ltrim($serviceId, '0') ?: '0';
                if (isset($index['byCore'][$core])) {
                    return $index['byCore'][$core];
                }
            }
        }

        if ($shortCode !== null && isset($index['byShort'][$shortCode])) {
            return $index['byShort'][$shortCode];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowPayloadForPartner(RevenueImportRow $row, RevenuePartner $partner, bool $preserveSentStatus): array
    {
        $payload = [
            'revenue_partner_id' => $partner->id,
            'partner_name' => $partner->partner_name ?: $row->partner_name,
            'service_id' => $partner->service_id ?: $row->service_id,
            'short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $row->short_code,
        ];

        if ($preserveSentStatus) {
            return $payload;
        }

        if (! $partner->is_active) {
            return [
                ...$payload,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => 'Inactive on master list.',
            ];
        }

        if (! $partner->hasUsablePhone()) {
            return [
                ...$payload,
                'status' => RevenueImportRowStatus::MissingPhone,
                'error' => 'Phone missing on master list.',
            ];
        }

        return [
            ...$payload,
            'status' => RevenueImportRowStatus::Matched,
            'error' => null,
        ];
    }
}
