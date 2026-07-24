<?php

namespace App\Services\Migration;

use App\Enums\CompanyApprovalStatus;
use App\Models\Company;
use App\Services\SmsService;
use App\Support\Migration\MvasDumpClientReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pilot importer: MVAS `.dump` clients → ownerless approved companies
 * ready for Fayda phone last-9 auto-claim.
 */
class PilotMvasCompanyImporter
{
    public function __construct(
        private readonly MvasDumpClientReader $reader,
        private readonly SmsService $sms,
    ) {}

    /**
     * @param  array{
     *   dump: string,
     *   limit?: int,
     *   dry_run?: bool,
     *   include_ethio_telecom?: bool,
     *   only_verified?: bool,
     *   ids?: list<int>
     * }  $options
     * @return array{selected: int, imported: int, skipped: int, companies: list<array<string, mixed>>}
     */
    public function import(array $options): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $includeEthio = (bool) ($options['include_ethio_telecom'] ?? false);
        $onlyVerified = (bool) ($options['only_verified'] ?? true);
        $onlyIds = array_values(array_filter(array_map('intval', $options['ids'] ?? [])));

        $selected = [];
        $seenPhones = [];

        foreach ($this->reader->clients($options['dump']) as $client) {
            if ($onlyIds !== [] && ! in_array($client['id'], $onlyIds, true)) {
                continue;
            }

            if ($client['deleted_at'] !== null) {
                continue;
            }

            if ($client['is_banned']) {
                continue;
            }

            if ($onlyVerified && ! $client['is_verified_client']) {
                continue;
            }

            $phone = $this->sms->normalizePhone($client['phone']);
            if ($phone === '' || ! preg_match('/^\d{9}$/', $phone)) {
                continue;
            }

            if (isset($seenPhones[$phone])) {
                continue;
            }

            $companyName = trim((string) ($client['company_name'] ?: $client['name']));
            if ($companyName === '') {
                continue;
            }

            if (
                ! $includeEthio
                && strcasecmp($companyName, 'ethio telecom') === 0
            ) {
                continue;
            }

            $seenPhones[$phone] = true;
            $selected[] = $client;

            if (count($selected) >= $limit) {
                break;
            }
        }

        $imported = 0;
        $skipped = 0;
        $companies = [];

        foreach ($selected as $client) {
            $phone = $this->sms->normalizePhone($client['phone']);
            $existing = Company::query()
                ->where(function ($query) use ($client, $phone) {
                    $query->where('legacy_mvas_client_id', $client['id'])
                        ->orWhereRaw(
                            "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                            [$phone],
                        );
                })
                ->first();

            if ($existing) {
                $skipped++;
                $companies[] = [
                    'action' => 'skipped',
                    'reason' => 'already_exists',
                    'legacy_mvas_client_id' => $client['id'],
                    'company_id' => $existing->id,
                    'phone' => $phone,
                    'name' => $existing->name,
                ];

                continue;
            }

            $payload = $this->mapToCompany($client, $phone);

            if ($dryRun) {
                $imported++;
                $companies[] = ['action' => 'would_import', ...$payload];

                continue;
            }

            $company = DB::transaction(function () use ($payload) {
                return Company::query()->create($payload);
            });

            $imported++;
            $companies[] = [
                'action' => 'imported',
                'company_id' => $company->id,
                'public_id' => $company->public_id,
                'legacy_mvas_client_id' => $client['id'],
                'name' => $company->name,
                'phone' => $company->phone,
                'tin' => $company->tin,
            ];

            Log::info('Pilot MVAS company imported from dump', [
                'legacy_mvas_client_id' => $client['id'],
                'company_id' => $company->id,
                'phone' => $phone,
            ]);
        }

        return [
            'selected' => count($selected),
            'imported' => $imported,
            'skipped' => $skipped,
            'companies' => $companies,
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    private function mapToCompany(array $client, string $phone): array
    {
        $name = trim((string) ($client['company_name'] ?: $client['name']));
        $addressParts = array_filter([
            trim((string) ($client['address'] ?? '')),
            trim((string) ($client['city'] ?? '')),
            trim((string) ($client['country'] ?? '')),
        ]);

        // MVAS clients have no TIN/license — provisional unique codes for pilot.
        $tin = 'MVAS-'.$client['id'];
        $license = 'MVAS-LIC-'.$client['id'];

        return [
            'name' => $name,
            'tin' => $tin,
            'license_number' => $license,
            'phone' => $phone,
            'email' => $client['email'] ?: null,
            'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
            'is_active' => true,
            'approval_status' => CompanyApprovalStatus::Approved,
            'approved_at' => now(),
            'approval_note' => 'Pilot import from MVAS .dump (legacy clients.id='.$client['id'].'). Ownerless until Fayda phone match.',
            'created_by_customer_id' => null,
            'legacy_mvas_client_id' => $client['id'],
        ];
    }
}
