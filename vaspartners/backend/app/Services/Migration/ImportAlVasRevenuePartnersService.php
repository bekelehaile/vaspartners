<?php

namespace App\Services\Migration;

use App\Models\RevenuePartner;
use App\Models\Service;
use App\Services\RevenuePartnerPhoneSyncService;
use App\Services\RevenuePartnerResolver;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Upsert Revenue Partners from Al VAS master Excel export (Service ID key).
 */
class ImportAlVasRevenuePartnersService
{
    public const DEFAULT_XLSX = 'data/Al vas data-august 31-2026.xlsx';

    public const SOURCE_TAG = 'al-vas-partners';

    /**
     * @return array{
     *   total: int,
     *   created: int,
     *   updated: int,
     *   unchanged: int,
     *   skipped: int,
     *   phones_set: int,
     *   phones_invalid: int,
     *   am_assigned: int,
     *   am_unknown: int,
     *   skipped_keys: list<string>,
     *   unknown_am_names: list<string>
     * }
     */
    public function import(string $path, bool $dryRun = false): array
    {
        /** @var list<array<string, mixed>> $partners */
        $partners = $this->loadPartnersFromPath($path);
        $catalogIds = $this->catalogIdsBySlug();
        $phoneSync = app(RevenuePartnerPhoneSyncService::class);

        $stats = [
            'total' => count($partners),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'phones_set' => 0,
            'phones_invalid' => 0,
            'am_assigned' => 0,
            'am_unknown' => 0,
            'skipped_keys' => [],
            'unknown_am_names' => [],
        ];

        $runner = function (array $chunk) use ($catalogIds, $phoneSync, $dryRun, &$stats): void {
            foreach ($chunk as $row) {
                $rawKey = RevenuePartnerResolver::normalize($row['service_id'] ?? null);
                if ($rawKey === null) {
                    $stats['skipped']++;
                    $stats['skipped_keys'][] = (string) ($row['service_id'] ?? '(blank)');

                    continue;
                }

                $isNumeric = ctype_digit($rawKey);
                $serviceId = $isNumeric ? $rawKey : null;
                $shortCode = $isNumeric ? null : $rawKey;

                $name = trim((string) ($row['partner_name'] ?? ''));
                if ($name === '') {
                    $name = 'Partner '.$rawKey;
                }

                $slug = $this->catalogSlugFromServiceType($row['service_type'] ?? null);
                $vasServiceId = $catalogIds[$slug] ?? $catalogIds['api'] ?? null;
                if (! $vasServiceId) {
                    $stats['skipped']++;
                    $stats['skipped_keys'][] = $rawKey;

                    continue;
                }

                $phone = $this->normalizeImportPhone($row['phone'] ?? null);
                if ($phone !== null && ! PhoneNumber::isValidLocalMobile($phone)) {
                    $stats['phones_invalid']++;
                }

                $amId = null;
                $amRaw = trim((string) ($row['account_manager'] ?? ''));
                if ($amRaw !== '') {
                    $amId = $phoneSync->resolveAccountManagerId($amRaw);
                    if ($amId === null) {
                        $stats['am_unknown']++;
                        $stats['unknown_am_names'][] = $amRaw;
                    }
                }

                $partner = $phoneSync->findPartner($serviceId, $shortCode);

                if (! $partner) {
                    if ($dryRun) {
                        $stats['created']++;
                        if ($phone) {
                            $stats['phones_set']++;
                        }
                        if ($amId) {
                            $stats['am_assigned']++;
                        }

                        continue;
                    }

                    $createShort = $shortCode;
                    if ($createShort && RevenuePartner::query()->where('short_code', $createShort)->exists()) {
                        $createShort = null;
                    }

                    RevenuePartner::query()->create([
                        'service_id' => $serviceId,
                        'short_code' => $createShort,
                        'partner_name' => $name,
                        'vas_service_id' => $vasServiceId,
                        'phone' => $phone,
                        'created_by_user_id' => $amId,
                        'is_active' => true,
                        'notes' => $this->buildNotes($row),
                    ]);

                    $stats['created']++;
                    if ($phone) {
                        $stats['phones_set']++;
                    }
                    if ($amId) {
                        $stats['am_assigned']++;
                    }

                    continue;
                }

                $changes = [];

                if ($partner->partner_name !== $name) {
                    $changes['partner_name'] = $name;
                }

                if ((int) $partner->vas_service_id !== (int) $vasServiceId) {
                    $changes['vas_service_id'] = $vasServiceId;
                }

                if ($shortCode && ! RevenuePartnerResolver::normalize($partner->short_code)) {
                    $existingShort = RevenuePartner::query()
                        ->where('short_code', $shortCode)
                        ->whereKeyNot($partner->id)
                        ->exists();
                    if (! $existingShort) {
                        $changes['short_code'] = $shortCode;
                    }
                }

                if ($phone !== null && (string) ($partner->phone ?? '') !== $phone) {
                    $changes['phone'] = $phone;
                    $stats['phones_set']++;
                }

                if ($amId && (int) ($partner->created_by_user_id ?? 0) !== $amId) {
                    $changes['created_by_user_id'] = $amId;
                    $stats['am_assigned']++;
                }

                $notes = $this->buildNotes($row);
                $existingNotes = trim((string) ($partner->notes ?? ''));
                if ($notes !== '' && ! str_contains($existingNotes, self::SOURCE_TAG)) {
                    $changes['notes'] = trim($existingNotes === '' ? $notes : $existingNotes."\n".$notes);
                }

                if ($changes === []) {
                    $stats['unchanged']++;

                    continue;
                }

                if (! $dryRun) {
                    $partner->forceFill($changes)->save();
                }

                $stats['updated']++;
            }
        };

        if ($dryRun) {
            $runner($partners);
        } else {
            foreach (array_chunk($partners, 100) as $chunk) {
                DB::transaction(function () use ($runner, $chunk): void {
                    $runner($chunk);
                });
            }
        }

        $stats['skipped_keys'] = array_values(array_unique($stats['skipped_keys']));
        $stats['unknown_am_names'] = array_values(array_unique($stats['unknown_am_names']));

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadPartnersFromPath(string $path): array
    {
        $absolute = File::isFile($path) ? $path : database_path($path);
        if (! File::isFile($absolute)) {
            throw new \InvalidArgumentException("File not found: {$absolute}");
        }

        $lower = strtolower($absolute);

        if (str_ends_with($lower, '.json')) {
            $payload = $this->loadJson($absolute);

            return $payload['partners'] ?? [];
        }

        if (str_ends_with($lower, '.xlsx')) {
            return $this->readXlsxPartners($absolute);
        }

        throw new \InvalidArgumentException('Expected .xlsx or .json file.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function readXlsxPartners(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Unable to open Excel file: {$path}");
        }

        $sharedStrings = $this->readXlsxSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('Missing sheet1 in Excel file.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new \RuntimeException('Invalid sheet XML in Excel file.');
        }

        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $partners = [];
        $rowIndex = 0;

        foreach ($sheet->sheetData->row as $row) {
            $rowIndex++;
            if ($rowIndex === 1) {
                continue;
            }

            $cells = $this->cellsFromXlsxRow($row, $sharedStrings);
            $serviceId = trim((string) ($cells[0] ?? ''));
            if ($serviceId === '') {
                continue;
            }

            $partners[] = [
                'service_id' => $serviceId,
                'partner_name' => trim((string) ($cells[1] ?? '')),
                'service_type' => trim((string) ($cells[2] ?? '')),
                'phone' => trim((string) ($cells[3] ?? '')),
                'account_manager' => trim((string) ($cells[4] ?? '')),
            ];
        }

        return $partners;
    }

    /**
     * @return list<string>
     */
    protected function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xmlRaw = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlRaw === false) {
            return [];
        }

        $xml = simplexml_load_string($xmlRaw);
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;

                continue;
            }

            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @return array<int, string>
     */
    protected function cellsFromXlsxRow(\SimpleXMLElement $row, array $sharedStrings): array
    {
        $cells = [];

        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            if ($ref === '') {
                continue;
            }

            if (! preg_match('/^([A-Z]+)/', $ref, $matches)) {
                continue;
            }

            $index = $this->xlsxColumnIndex($matches[1]);
            $type = (string) ($cell['t'] ?? '');
            $value = isset($cell->v) ? (string) $cell->v : '';

            if ($type === 's') {
                $cells[$index] = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $cells[$index] = (string) $cell->is->t;
            } else {
                $cells[$index] = $value;
            }
        }

        ksort($cells);

        return $cells;
    }

    protected function xlsxColumnIndex(string $column): int
    {
        $column = strtoupper($column);
        $index = 0;

        for ($i = 0; $i < strlen($column); $i++) {
            $index = ($index * 26) + (ord($column[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    protected function catalogSlugFromServiceType(mixed $type): string
    {
        $label = strtoupper(trim((string) $type));

        return match ($label) {
            'CRBT' => 'crbt',
            'API' => 'api',
            'MT' => 'mt-mobile-terminated-premium',
            'MO' => 'mo-mobile-originating',
            'VOICE-PREMIUM', 'VOICE PREMIUM', 'VOICE' => 'voice-premium',
            default => 'api',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function buildNotes(array $row): string
    {
        $type = trim((string) ($row['service_type'] ?? ''));

        return self::SOURCE_TAG.($type !== '' ? ' | service_type='.$type : '');
    }

    protected function normalizeImportPhone(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = trim((string) $raw);
        if ($text === '' || strtoupper($text) === 'NA') {
            return null;
        }

        foreach (preg_split('/[\/|,;]+/', $text) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $phone = PhoneNumber::normalizeNullable($part);
            if ($phone !== null && PhoneNumber::isValidLocalMobile($phone)) {
                return $phone;
            }
        }

        $phone = PhoneNumber::normalizeNullable($text);

        return $phone !== '' ? $phone : null;
    }

    /**
     * @return array<string, int>
     */
    protected function catalogIdsBySlug(): array
    {
        return Service::query()
            ->whereNotNull('slug')
            ->pluck('id', 'slug')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Invalid JSON: {$path}");
        }

        return $decoded;
    }
}
