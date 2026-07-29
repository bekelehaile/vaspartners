<?php

namespace App\Services\Migration;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Models\Company;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\Service;
use App\Services\BulkMessageService;
use App\Services\RevenuePartnerPhoneSyncService;
use App\Services\RevenuePartnerResolver;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Loads committed Excel snapshots (no runtime XLSX parse).
 *
 * Snapshots:
 *   database/data/revenue/excel_revenue_partners.json
 *   database/data/revenue/excel_revenue_rows.json
 *
 * Regenerate with: scripts/extract_revenue_excel.py
 */
class SeedRevenueExcelSnapshotService
{
    public const PARTNERS_FILE = 'data/revenue/excel_revenue_partners.json';

    public const ROWS_FILE = 'data/revenue/excel_revenue_rows.json';

    public const SOURCE_PREFIX = 'excel-snapshot';

    /**
     * @return array{created:int, updated:int, skipped:int, total:int}
     */
    public function seedPartners(): array
    {
        $payload = $this->loadJson(self::PARTNERS_FILE);
        /** @var list<array<string, mixed>> $partners */
        $partners = $payload['partners'] ?? [];
        $catalogIds = $this->catalogIdsBySlug();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($partners, $catalogIds, &$created, &$updated, &$skipped): void {
            foreach ($partners as $row) {
                $serviceId = RevenuePartnerResolver::normalize($row['service_id'] ?? null);
                if ($serviceId === null) {
                    $skipped++;

                    continue;
                }

                $shortCode = RevenuePartnerResolver::normalize($row['short_code'] ?? null);
                $name = trim((string) ($row['partner_name'] ?? ''));
                if ($name === '') {
                    $name = 'Partner '.$serviceId;
                }

                $slug = (string) ($row['catalog_slug'] ?? 'api');
                $vasServiceId = $catalogIds[$slug] ?? $catalogIds['api'] ?? null;
                if (! $vasServiceId) {
                    $skipped++;

                    continue;
                }

                $partner = RevenuePartner::query()->where('service_id', $serviceId)->first();

                if (! $partner) {
                    $core = ltrim($serviceId, '0') ?: '0';
                    $partner = app(RevenuePartnerPhoneSyncService::class)->findPartner($serviceId, $shortCode);
                }

                if (! $partner && $shortCode) {
                    $partner = RevenuePartner::query()
                        ->where('short_code', $shortCode)
                        ->orderBy('id')
                        ->first();
                }

                if (! $partner) {
                    // Avoid unique collisions when Excel uses short code as service_id.
                    $createShort = $shortCode;
                    if ($createShort && RevenuePartner::query()->where('short_code', $createShort)->exists()) {
                        $createShort = null;
                    }
                    if ($createShort === $serviceId) {
                        // Prefer keeping short_code column empty when the ID is clearly a short code duplicate.
                        $ownedByShort = RevenuePartner::query()->where('short_code', $serviceId)->exists();
                        if ($ownedByShort) {
                            $skipped++;

                            continue;
                        }
                    }

                    RevenuePartner::query()->create([
                        'service_id' => $serviceId,
                        'short_code' => $createShort,
                        'partner_name' => $name,
                        'vas_service_id' => $vasServiceId,
                        'phone' => null,
                        'created_by_user_id' => null,
                        'is_active' => true,
                        'notes' => $this->notesFromSheets($row['source_sheets'] ?? []),
                    ]);
                    $created++;

                    continue;
                }

                $changes = [];

                // Prefer longer / zero-padded service ID when values are equivalent.
                if ($partner->service_id !== $serviceId) {
                    $existingCore = ltrim((string) $partner->service_id, '0') ?: '0';
                    $incomingCore = ltrim($serviceId, '0') ?: '0';
                    if ($existingCore === $incomingCore && strlen($serviceId) > strlen((string) $partner->service_id)) {
                        $taken = RevenuePartner::query()
                            ->where('service_id', $serviceId)
                            ->whereKeyNot($partner->id)
                            ->exists();
                        if (! $taken) {
                            $changes['service_id'] = $serviceId;
                        }
                    }
                }

                if ($shortCode && ! RevenuePartnerResolver::normalize($partner->short_code)) {
                    $taken = RevenuePartner::query()
                        ->where('short_code', $shortCode)
                        ->whereKeyNot($partner->id)
                        ->exists();
                    if (! $taken) {
                        $changes['short_code'] = $shortCode;
                    }
                }

                if (! filled($partner->partner_name) || $partner->partner_name === 'Partner '.$partner->service_id) {
                    $changes['partner_name'] = $name;
                }

                if (! $partner->vas_service_id) {
                    $changes['vas_service_id'] = $vasServiceId;
                }

                if ($changes !== []) {
                    $partner->forceFill($changes)->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($partners),
            'merged_duplicates' => $this->mergeUnownedZeroPadDuplicates(),
        ];
    }

    /**
     * Collapse unowned partners that differ only by leading zeros on service_id.
     * Prefer the longer (zero-padded) service_id and re-point import rows.
     */
    protected function mergeUnownedZeroPadDuplicates(): int
    {
        $merged = 0;
        $groups = RevenuePartner::query()
            ->whereNull('created_by_user_id')
            ->selectRaw("TRIM(LEADING '0' FROM service_id) as core")
            ->groupByRaw("TRIM(LEADING '0' FROM service_id)")
            ->havingRaw('COUNT(*) > 1')
            ->pluck('core');

        foreach ($groups as $core) {
            $rows = RevenuePartner::query()
                ->whereNull('created_by_user_id')
                ->whereRaw("TRIM(LEADING '0' FROM service_id) = ?", [(string) $core])
                ->orderByRaw('LENGTH(service_id) DESC')
                ->orderBy('id')
                ->get();

            if ($rows->count() < 2) {
                continue;
            }

            /** @var RevenuePartner $keep */
            $keep = $rows->first();
            foreach ($rows->skip(1) as $drop) {
                if ($drop->short_code && ! $keep->short_code) {
                    $taken = RevenuePartner::query()
                        ->where('short_code', $drop->short_code)
                        ->whereKeyNot($keep->id)
                        ->exists();
                    if (! $taken) {
                        $keep->forceFill(['short_code' => $drop->short_code])->save();
                    }
                }

                RevenueImportRow::query()
                    ->where('revenue_partner_id', $drop->id)
                    ->update([
                        'revenue_partner_id' => $keep->id,
                        'service_id' => $keep->service_id,
                        'short_code' => $keep->short_code,
                        'partner_name' => $keep->partner_name,
                    ]);

                // Model blocks Eloquent deletes; remove orphan seed duplicate at SQL level.
                DB::table('revenue_partners')->where('id', $drop->id)->delete();
                $merged++;
            }
        }

        return $merged;
    }

    /**
     * Upsert monthly import batches from snapshot rows (idempotent replace of unsent snapshot imports).
     *
     * @return array{imports:int, rows:int, skipped_sent:int}
     */
    public function seedMonthlyImports(): array
    {
        $payload = $this->loadJson(self::ROWS_FILE);
        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'] ?? [];
        $catalogIds = $this->catalogIdsBySlug();

        // period|catalog_slug => list of row payloads (deduped by service_id|short_code)
        $batches = [];
        foreach ($rows as $row) {
            $period = trim((string) ($row['period'] ?? ''));
            $slug = (string) ($row['catalog_slug'] ?? '');
            if ($period === '' || $slug === '' || ! isset($catalogIds[$slug])) {
                continue;
            }

            $serviceId = RevenuePartnerResolver::normalize($row['service_id'] ?? null);
            $shortCode = RevenuePartnerResolver::normalize($row['short_code'] ?? null);
            if ($serviceId === null && $shortCode === null) {
                continue;
            }

            $amount = isset($row['amount']) && is_numeric($row['amount'])
                ? round((float) $row['amount'], 4)
                : null;
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $key = $period.'|'.$slug;
            $dedupe = ($serviceId ?? '').'|'.($shortCode ?? '');
            $batches[$key][$dedupe] = [
                'period' => $period,
                'catalog_slug' => $slug,
                'vas_service_id' => $catalogIds[$slug],
                'service_id' => $serviceId ?? $shortCode,
                'short_code' => $shortCode,
                'partner_name' => RevenuePartnerResolver::normalize($row['partner_name'] ?? null),
                'amount' => $amount,
                'sheet' => (string) ($row['sheet'] ?? ''),
                'excel_row' => $row['excel_row'] ?? null,
            ];
        }

        $importCount = 0;
        $rowCount = 0;
        $skippedSent = 0;

        DB::transaction(function () use ($batches, &$importCount, &$rowCount, &$skippedSent): void {
            foreach ($batches as $group) {
                $first = reset($group);
                $period = $first['period'];
                $vasServiceId = (int) $first['vas_service_id'];
                $slug = $first['catalog_slug'];
                $source = self::SOURCE_PREFIX.'|'.$period.'|'.$slug;

                $existing = RevenueImport::query()
                    ->where('source_filename', $source)
                    ->first();

                if ($existing && (filled($existing->bulk_message_id)
                    || in_array($existing->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true))) {
                    $skippedSent++;

                    continue;
                }

                if ($existing) {
                    $existing->rows()->delete();
                    $import = $existing;
                    $import->forceFill([
                        'title' => $this->importTitle($slug, $period),
                        'period' => $period,
                        'vas_service_id' => $vasServiceId,
                        'status' => RevenueImportStatus::Draft,
                        'message_template' => BulkMessageService::DEFAULT_MESSAGE,
                        'imported_at' => now(),
                    ])->save();
                } else {
                    $import = RevenueImport::query()->create([
                        'title' => $this->importTitle($slug, $period),
                        'period' => $period,
                        'vas_service_id' => $vasServiceId,
                        'source_filename' => $source,
                        'status' => RevenueImportStatus::Draft,
                        'message_template' => BulkMessageService::DEFAULT_MESSAGE,
                        'created_by_user_id' => null,
                        'imported_at' => now(),
                    ]);
                }

                $n = 0;
                foreach ($group as $item) {
                    $n++;
                    $partner = app(RevenuePartnerPhoneSyncService::class)
                        ->findPartner(
                            is_string($item['service_id']) ? $item['service_id'] : null,
                            is_string($item['short_code']) ? $item['short_code'] : null,
                        );

                    $status = RevenueImportRowStatus::MissingPartner;
                    $error = 'Unresolved: service ID / short code not in partner master list.';
                    if ($partner) {
                        if (! $partner->is_active) {
                            $status = RevenueImportRowStatus::Invalid;
                            $error = 'Inactive on master list.';
                        } elseif (! $partner->hasUsablePhone()) {
                            $status = RevenueImportRowStatus::MissingPhone;
                            $error = 'Phone missing on master list.';
                        } else {
                            $status = RevenueImportRowStatus::Matched;
                            $error = null;
                        }
                    }

                    RevenueImportRow::query()->create([
                        'revenue_import_id' => $import->id,
                        'revenue_partner_id' => $partner?->id,
                        'vas_service_id' => $vasServiceId,
                        'row_number' => $n,
                        'service_id' => $partner?->service_id ?? $item['service_id'],
                        'short_code' => RevenuePartnerResolver::normalize($partner?->short_code) ?? $item['short_code'],
                        'partner_name' => $partner?->partner_name ?? $item['partner_name'],
                        'amount' => $item['amount'],
                        'amount_raw' => (string) $item['amount'],
                        'status' => $status,
                        'error' => $error,
                        'raw' => [
                            'sheet' => $item['sheet'],
                            'excel_row' => $item['excel_row'],
                            'source' => self::SOURCE_PREFIX,
                        ],
                    ]);
                    $rowCount++;
                }

                // Historical Excel seed — visible in partner portal (not a draft SMS campaign).
                $import->forceFill([
                    'status' => RevenueImportStatus::Completed,
                    'imported_at' => $import->imported_at ?? now(),
                ])->save();
                $importCount++;
            }
        });

        return [
            'imports' => $importCount,
            'rows' => $rowCount,
            'skipped_sent' => $skippedSent,
        ];
    }

    /**
     * Link revenue partners to portal companies by shared phone (last 9 digits).
     *
     * @return array{linked: int, already: int, no_match: int}
     */
    public function linkPartnersToCompanies(): array
    {
        $linked = 0;
        $already = 0;
        $noMatch = 0;

        RevenuePartner::query()
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($partners) use (&$linked, &$already, &$noMatch): void {
                foreach ($partners as $partner) {
                    if ($partner->company_id) {
                        $already++;

                        continue;
                    }

                    $phone = PhoneNumber::normalizeNullable($partner->phone);
                    if ($phone === null) {
                        $noMatch++;

                        continue;
                    }

                    $company = Company::query()
                        ->whereRaw(
                            "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                            [$phone]
                        )
                        ->orderByRaw('CASE WHEN tin_validated THEN 0 ELSE 1 END')
                        ->orderBy('id')
                        ->first();

                    if (! $company) {
                        $noMatch++;

                        continue;
                    }

                    $partner->forceFill(['company_id' => $company->id])->save();
                    $linked++;
                }
            });

        return compact('linked', 'already', 'noMatch');
    }

    /**
     * @return array{meta: array<string, mixed>, partners?: list<array<string, mixed>>, rows?: list<array<string, mixed>>}
     */
    protected function loadJson(string $relative): array
    {
        $path = database_path($relative);
        if (! File::isFile($path)) {
            throw new \RuntimeException("Revenue snapshot missing: {$path}. Run scripts/extract_revenue_excel.py first.");
        }

        /** @var array{meta: array<string, mixed>, partners?: list<array<string, mixed>>, rows?: list<array<string, mixed>>} $data */
        $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, int>
     */
    protected function catalogIdsBySlug(): array
    {
        return Service::query()
            ->where('is_active', true)
            ->pluck('id', 'slug')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $sheets
     */
    protected function notesFromSheets(array $sheets): ?string
    {
        $sheets = array_values(array_filter(array_map('strval', $sheets)));
        if ($sheets === []) {
            return null;
        }

        return 'Seeded from Excel sheets: '.implode(', ', $sheets);
    }

    protected function importTitle(string $slug, string $period): string
    {
        $service = Service::query()->where('slug', $slug)->value('name') ?: $slug;

        return "{$service} — {$period} (Excel seed)";
    }
}
