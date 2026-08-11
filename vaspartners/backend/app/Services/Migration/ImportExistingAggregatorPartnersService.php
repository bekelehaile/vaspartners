<?php

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\RevenuePartner;
use App\Models\Service;
use App\Services\RevenuePartnerPhoneSyncService;
use App\Services\RevenuePartnerResolver;
use App\Support\PartnerCompanyNameMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Import legacy master-aggregator service offers into revenue_partners.
 *
 * Unique key: Service ID (zero-pad variants collapsed via finder).
 * Also stores Product ID, SPID, Short Code, and phone when valid.
 */
class ImportExistingAggregatorPartnersService
{
    public const DEFAULT_FILE = 'data/revenue/existing_aggregator_partners.json';

    public const SOURCE_TAG = 'aggregator-existing';

    /**
     * @return array{
     *   total: int,
     *   created: int,
     *   updated: int,
     *   unchanged: int,
     *   skipped: int,
     *   phones_set: int,
     *   phones_invalid: int,
     *   linked: int,
     *   already_linked: int,
     *   no_company_match: int,
     *   skipped_keys: list<string>
     * }
     */
    public function import(
        string $path,
        bool $dryRun = false,
        bool $linkCompanies = true,
        bool $overwritePhone = true,
    ): array {
        $payload = $this->loadJson($path);
        /** @var list<array<string, mixed>> $partners */
        $partners = $payload['partners'] ?? [];
        $catalogIds = $this->catalogIdsBySlug();

        $stats = [
            'total' => count($partners),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'phones_set' => 0,
            'phones_invalid' => 0,
            'linked' => 0,
            'already_linked' => 0,
            'no_company_match' => 0,
            'skipped_keys' => [],
        ];

        $runner = function (array $chunk) use ($catalogIds, $dryRun, $linkCompanies, $overwritePhone, &$stats): void {
            $finder = app(RevenuePartnerPhoneSyncService::class);

            foreach ($chunk as $row) {                $serviceId = RevenuePartnerResolver::normalize($row['service_id'] ?? null);
                if ($serviceId === null || ! ctype_digit($serviceId)) {
                    $stats['skipped']++;
                    $stats['skipped_keys'][] = (string) ($row['service_id'] ?? '(blank)');

                    continue;
                }

                $shortCode = RevenuePartnerResolver::normalize($row['short_code'] ?? null);
                $productId = RevenuePartnerResolver::normalize($row['product_id'] ?? null);
                $spid = RevenuePartnerResolver::normalize($row['spid'] ?? null);
                $name = trim((string) ($row['partner_name'] ?? ''));
                if ($name === '') {
                    $name = 'Partner '.$serviceId;
                }

                $slug = (string) ($row['catalog_slug'] ?? 'api');
                $vasServiceId = $catalogIds[$slug] ?? $catalogIds['api'] ?? null;
                if (! $vasServiceId) {
                    $stats['skipped']++;
                    $stats['skipped_keys'][] = $serviceId;

                    continue;
                }

                $phoneRaw = $row['phone'] ?? $row['contact_number'] ?? null;
                $phone = $this->normalizeImportPhone($phoneRaw);
                $phoneIsValid = $phone !== null && PhoneNumber::isValidLocalMobile($phone);
                if ($phone !== null && ! $phoneIsValid) {
                    $stats['phones_invalid']++;
                }

                $notes = $this->buildNotes($row);
                $partner = $finder->findPartner($serviceId, $shortCode);

                if (! $partner) {
                    if ($dryRun) {
                        $stats['created']++;
                        if ($phone) {
                            $stats['phones_set']++;
                        }
                        if ($linkCompanies && $this->findCompanyIdForName($name)) {
                            $stats['linked']++;
                        } else {
                            $stats['no_company_match']++;
                        }

                        continue;
                    }

                    $createShort = $shortCode;
                    if ($createShort && RevenuePartner::query()->where('short_code', $createShort)->exists()) {
                        $createShort = null;
                    }

                    $companyId = $linkCompanies ? $this->findCompanyIdForName($name) : null;

                    RevenuePartner::query()->create([
                        'service_id' => $serviceId,
                        'product_id' => $productId,
                        'spid' => $spid,
                        'short_code' => $createShort,
                        'partner_name' => $name,
                        'vas_service_id' => $vasServiceId,
                        'phone' => $phone,
                        'company_id' => $companyId,
                        'created_by_user_id' => null,
                        'is_active' => true,
                        'notes' => $notes,
                    ]);

                    $stats['created']++;
                    if ($phone) {
                        $stats['phones_set']++;
                    }
                    if ($companyId) {
                        $stats['linked']++;
                    } else {
                        $stats['no_company_match']++;
                    }

                    continue;
                }

                $changes = [];

                if ($partner->service_id !== $serviceId) {
                    $existingCore = ltrim((string) $partner->service_id, '0') ?: '0';
                    $incomingCore = ltrim($serviceId, '0') ?: '0';
                    if ($existingCore === $incomingCore && strlen($serviceId) >= strlen((string) $partner->service_id)) {
                        $taken = RevenuePartner::query()
                            ->where('service_id', $serviceId)
                            ->whereKeyNot($partner->id)
                            ->exists();
                        if (! $taken) {
                            $changes['service_id'] = $serviceId;
                        }
                    }
                }

                if ($productId && $partner->product_id !== $productId) {
                    $changes['product_id'] = $productId;
                }
                if ($spid && $partner->spid !== $spid) {
                    $changes['spid'] = $spid;
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

                if ($phone !== null) {
                    $current = trim((string) ($partner->phone ?? ''));
                    $shouldSet = $overwritePhone
                        || $current === ''
                        || ! PhoneNumber::isValidLocalMobile($current);
                    if ($shouldSet && $current !== $phone) {
                        $changes['phone'] = $phone;
                        $stats['phones_set']++;
                    }
                }

                $existingNotes = trim((string) ($partner->notes ?? ''));
                if ($notes !== '' && ! str_contains($existingNotes, self::SOURCE_TAG)) {
                    $changes['notes'] = trim($existingNotes === '' ? $notes : $existingNotes."\n".$notes);
                }

                $linkedNow = false;
                if ($linkCompanies && ! $partner->company_id) {
                    $companyId = $this->findCompanyIdForName($name);
                    if ($companyId) {
                        $changes['company_id'] = $companyId;
                        $linkedNow = true;
                    }
                }

                if ($changes === []) {
                    $stats['unchanged']++;
                    if ($partner->company_id) {
                        $stats['already_linked']++;
                    } elseif ($linkCompanies) {
                        $stats['no_company_match']++;
                    }

                    continue;
                }

                if (! $dryRun) {
                    $partner->forceFill($changes)->save();
                }

                $stats['updated']++;
                if ($linkedNow) {
                    $stats['linked']++;
                } elseif ($partner->company_id || isset($changes['company_id'])) {
                    $stats['already_linked']++;
                } elseif ($linkCompanies) {
                    $stats['no_company_match']++;
                }
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

        return $stats;
    }

    /**
     * Prefer a valid local mobile; otherwise keep the raw contact string for manual cleanup.
     */
    protected function normalizeImportPhone(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = trim((string) $raw);
        if ($text === '' || strtoupper($text) === 'NA') {
            return null;
        }

        // Excel often has "251-911-52-0105 / 251-911-52-0105"
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

        // Keep first segment / full raw for manual filter (column max 32).
        $first = trim((string) (preg_split('/[\/|,;]+/', $text)[0] ?? $text));

        return mb_substr($first !== '' ? $first : $text, 0, 32);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function buildNotes(array $row): string
    {
        $parts = [
            'source='.self::SOURCE_TAG,
            'service_type='.trim((string) ($row['service_type'] ?? '')),
        ];

        foreach ([
            'service_detail' => 'detail',
            'contact_name' => 'contact_name',
            'contact_email' => 'contact_email',
            'free_trial_period' => 'free_trial',
            'price' => 'price',
            'charging_mode' => 'charging',
            'channel' => 'channel',
            'customer_base' => 'customer_base',
        ] as $key => $label) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $label.'='.$value;
        }

        return implode(' | ', $parts);
    }

    protected function findCompanyIdForName(string $partnerName): ?int
    {
        $normalized = PartnerCompanyNameMatcher::normalize($partnerName);
        if ($normalized === '') {
            return null;
        }

        $exact = Company::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($partnerName)])
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($exact->count() === 1) {
            return (int) $exact->first()->id;
        }

        $candidates = Company::query()
            ->where(function ($q) use ($partnerName, $normalized): void {
                $q->where('name', 'ilike', $partnerName)
                    ->orWhereRaw(
                        "regexp_replace(lower(name), '[^a-z0-9]+', '', 'g') like ?",
                        ['%'.mb_substr($normalized, 0, 12).'%']
                    );
            })
            ->orderBy('id')
            ->limit(40)
            ->get(['id', 'name']);

        $matches = $candidates->filter(
            fn (Company $company) => PartnerCompanyNameMatcher::matches($partnerName, $company->name)
        )->values();

        if ($matches->count() === 1) {
            return (int) $matches->first()->id;
        }

        return null;
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
        $absolute = File::isFile($path) ? $path : database_path($path);
        if (! File::isFile($absolute)) {
            throw new \InvalidArgumentException("Snapshot not found: {$absolute}");
        }

        $decoded = json_decode((string) File::get($absolute), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Invalid JSON snapshot: {$absolute}");
        }

        return $decoded;
    }
}
