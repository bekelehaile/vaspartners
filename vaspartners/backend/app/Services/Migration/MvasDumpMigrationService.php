<?php

namespace App\Services\Migration;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Priority;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\SmsService;
use App\Support\Migration\MvasDumpClientReader;
use App\Support\Migration\MvasDumpTableReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Full MVAS `.dump` → VAS Partners seed for companies, customers, and tickets.
 */
class MvasDumpMigrationService
{
    /** @var array<int, TicketStatus> */
    private const STATUS_MAP = [
        1 => TicketStatus::Open,
        2 => TicketStatus::InProgress,
        3 => TicketStatus::Completed,
        4 => TicketStatus::Completed, // Approved
        5 => TicketStatus::Rejected,
        6 => TicketStatus::Closed,
    ];

    public function __construct(
        private readonly MvasDumpClientReader $clientReader,
        private readonly MvasDumpTableReader $tableReader,
        private readonly SmsService $sms,
    ) {}

    /**
     * @param  array{
     *   dump: string,
     *   company_limit?: int|null,
     *   ticket_limit?: int|null,
     *   dry_run?: bool,
     *   include_ethio_telecom?: bool,
     *   only_verified?: bool,
     *   client_ids?: list<int>,
     *   skip_companies?: bool,
     *   skip_customers?: bool,
     *   skip_tickets?: bool,
     *   link_memberships?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function migrate(array $options): array
    {
        $dump = $options['dump'];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $companyLimit = isset($options['company_limit']) ? max(0, (int) $options['company_limit']) : null;
        $ticketLimit = isset($options['ticket_limit']) ? max(0, (int) $options['ticket_limit']) : null;
        $includeEthio = (bool) ($options['include_ethio_telecom'] ?? false);
        $onlyVerified = (bool) ($options['only_verified'] ?? false);
        $onlyClientIds = array_values(array_filter(array_map('intval', $options['client_ids'] ?? [])));
        // Default false: companies stay ownerless until Fayda phone-claim or admin assign.
        $linkMemberships = (bool) ($options['link_memberships'] ?? false);

        $catalog = $this->buildCatalogMaps();
        $fallbackServiceId = (int) (Service::query()->orderBy('id')->value('id') ?? 0);
        $fallbackRequisitionId = (int) (Requisition::query()->orderBy('id')->value('id') ?? 0);
        $fallbackCategoryId = (int) (Category::query()->orderBy('id')->value('id') ?? 0);
        $defaultPriorityId = Priority::query()->where('code', 'medium')->value('id')
            ?? Priority::query()->orderBy('id')->value('id');

        if ($fallbackServiceId < 1 || $fallbackRequisitionId < 1 || $fallbackCategoryId < 1) {
            throw new \RuntimeException('Catalog is empty. Run CatalogSeeder before MVAS dump migration.');
        }

        $stats = [
            'companies' => ['imported' => 0, 'skipped' => 0, 'selected' => 0],
            'customers' => ['imported' => 0, 'skipped' => 0, 'selected' => 0],
            'tickets' => ['imported' => 0, 'skipped' => 0, 'selected' => 0, 'orphaned' => 0],
            'memberships' => ['linked' => 0, 'skipped' => 0],
            'dry_run' => $dryRun,
        ];

        /** @var array<int, int> legacy client id → company id */
        $companyByClient = [];
        /** @var array<int, int> legacy client id → customer id */
        $customerByClient = [];
        /** @var array<string, true> */
        $usedPhones = [];
        /** @var array<string, true> */
        $usedEmails = [];

        // Preload existing unique phones/emails so we do not collide.
        foreach (Customer::query()->whereNotNull('phone_number')->pluck('phone_number') as $phone) {
            $usedPhones[(string) $phone] = true;
        }
        foreach (Customer::query()->whereNotNull('email')->pluck('email') as $email) {
            $usedEmails[strtolower((string) $email)] = true;
        }

        foreach (Company::query()->whereNotNull('legacy_mvas_client_id')->get(['id', 'legacy_mvas_client_id']) as $company) {
            $companyByClient[(int) $company->legacy_mvas_client_id] = (int) $company->id;
        }
        foreach (Customer::query()->whereNotNull('legacy_mvas_client_id')->get(['id', 'legacy_mvas_client_id']) as $customer) {
            $customerByClient[(int) $customer->legacy_mvas_client_id] = (int) $customer->id;
        }

        if (! ($options['skip_companies'] ?? false) || ! ($options['skip_customers'] ?? false)) {
            $clientsSelected = 0;

            foreach ($this->clientReader->clients($dump) as $client) {
                if ($onlyClientIds !== [] && ! in_array($client['id'], $onlyClientIds, true)) {
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

                $companyName = trim((string) ($client['company_name'] ?: $client['name']));
                if ($companyName === '') {
                    continue;
                }
                if (! $includeEthio && strcasecmp($companyName, 'ethio telecom') === 0) {
                    continue;
                }

                $phone = $this->sms->normalizePhone($client['phone']);
                if ($phone === '' || ! preg_match('/^\d{9}$/', $phone)) {
                    $phone = '';
                }

                if ($companyLimit !== null && $clientsSelected >= $companyLimit) {
                    break;
                }
                $clientsSelected++;

                if (! ($options['skip_companies'] ?? false)) {
                    $stats['companies']['selected']++;
                    $companyId = $this->upsertCompany($client, $companyName, $phone, $dryRun, $companyByClient, $stats);
                    if ($companyId !== null) {
                        $companyByClient[$client['id']] = $companyId;
                    }
                }

                if (! ($options['skip_customers'] ?? false)) {
                    $stats['customers']['selected']++;
                    $customerId = $this->upsertCustomer(
                        $client,
                        $companyName,
                        $phone,
                        $dryRun,
                        $customerByClient,
                        $usedPhones,
                        $usedEmails,
                        $stats,
                    );
                    if ($customerId !== null) {
                        $customerByClient[$client['id']] = $customerId;
                    }

                    if (
                        $linkMemberships
                        && ! $dryRun
                        && isset($companyByClient[$client['id']], $customerByClient[$client['id']])
                    ) {
                        $this->linkOwnerMembership(
                            $customerByClient[$client['id']],
                            $companyByClient[$client['id']],
                            $stats,
                        );
                    }
                }
            }
        }

        if (! ($options['skip_tickets'] ?? false)) {
            $ticketsSeen = 0;

            foreach ($this->tableReader->rows($dump, 'tickets') as $row) {
                $ticket = $this->mapTicketRow($row);
                if ($ticket === null) {
                    continue;
                }
                if ($ticket['deleted_at'] !== null) {
                    continue;
                }
                if ($onlyClientIds !== [] && ! in_array($ticket['client_id'], $onlyClientIds, true)) {
                    continue;
                }
                if ($companyLimit !== null && ! isset($customerByClient[$ticket['client_id']])) {
                    // When limiting companies, only import tickets for imported clients.
                    // Still allow tickets whose customer was already in DB from a prior run.
                    $existingCustomerId = Customer::query()
                        ->where('legacy_mvas_client_id', $ticket['client_id'])
                        ->value('id');
                    if (! $existingCustomerId) {
                        continue;
                    }
                    $customerByClient[$ticket['client_id']] = (int) $existingCustomerId;
                }

                if ($ticketLimit !== null && $ticketsSeen >= $ticketLimit) {
                    break;
                }
                $ticketsSeen++;
                $stats['tickets']['selected']++;

                $customerId = $customerByClient[$ticket['client_id']] ?? null;
                if ($customerId === null) {
                    $stats['tickets']['orphaned']++;

                    continue;
                }

                $serviceId = $catalog['services'][$ticket['service_id']] ?? $fallbackServiceId;
                $requisitionId = $catalog['requisitions'][$ticket['requisition_id']] ?? $fallbackRequisitionId;
                $categoryId = $catalog['categories'][$ticket['category_id']]
                    ?? (int) (Service::query()->whereKey($serviceId)->value('category_id') ?: $fallbackCategoryId);

                $status = self::STATUS_MAP[$ticket['status_id']] ?? TicketStatus::Open;
                $priorityId = $defaultPriorityId ? (int) $defaultPriorityId : null;

                if (Ticket::query()->where('legacy_mvas_ticket_id', $ticket['id'])->exists()
                    || Ticket::query()->where('tt_number', $ticket['tt_number'])->exists()) {
                    $stats['tickets']['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['tickets']['imported']++;

                    continue;
                }

                DB::transaction(function () use (
                    $ticket,
                    $customerId,
                    $serviceId,
                    $requisitionId,
                    $categoryId,
                    $status,
                    $priorityId,
                ): void {
                    $model = new Ticket([
                        'tt_number' => $ticket['tt_number'],
                        'legacy_mvas_ticket_id' => $ticket['id'],
                        'customer_id' => $customerId,
                        'service_id' => $serviceId,
                        'requisition_id' => $requisitionId,
                        'category_id' => $categoryId,
                        'priority_id' => $priorityId,
                        'status' => $status,
                        'document_review_status' => DocumentReviewStatus::Pending,
                        'building' => $ticket['building'],
                        'location' => $ticket['location'],
                        'description' => $ticket['description'],
                        'escalated_at' => $ticket['escalated_at'],
                        'rejected_at' => $ticket['rejected_at'],
                        'closed_at' => $ticket['closed_at'],
                        'completed_at' => in_array($status, [TicketStatus::Completed, TicketStatus::Closed], true)
                            ? ($ticket['closed_at'] ?? $ticket['updated_at'] ?? now())
                            : null,
                        'assigned_at' => $status === TicketStatus::InProgress
                            ? ($ticket['updated_at'] ?? now())
                            : null,
                    ]);
                    $model->created_at = $ticket['created_at'] ?? now();
                    $model->updated_at = $ticket['updated_at'] ?? now();
                    $model->save();
                });

                $stats['tickets']['imported']++;
            }
        }

        Log::info('MVAS dump migration finished', $stats);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<int, int>  $companyByClient
     * @param  array<string, mixed>  $stats
     */
    private function upsertCompany(
        array $client,
        string $companyName,
        string $phone,
        bool $dryRun,
        array $companyByClient,
        array &$stats,
    ): ?int {
        if (isset($companyByClient[$client['id']])) {
            $stats['companies']['skipped']++;

            return $companyByClient[$client['id']];
        }

        $existing = null;
        if ($phone !== '') {
            $existing = Company::query()
                ->whereRaw(
                    "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                    [$phone],
                )
                ->first();
        }

        if ($existing) {
            if ($existing->legacy_mvas_client_id === null && ! $dryRun) {
                $existing->forceFill(['legacy_mvas_client_id' => $client['id']])->save();
            }
            $stats['companies']['skipped']++;

            return (int) $existing->id;
        }

        if ($dryRun) {
            $stats['companies']['imported']++;

            return null;
        }

        $addressParts = array_filter([
            trim((string) ($client['address'] ?? '')),
            trim((string) ($client['city'] ?? '')),
            trim((string) ($client['country'] ?? '')),
        ]);

        $company = Company::query()->create([
            'name' => $companyName,
            'tin' => 'MVAS-'.$client['id'],
            'license_number' => 'MVAS-LIC-'.$client['id'],
            'phone' => $phone !== '' ? $phone : null,
            'email' => $client['email'] ?: null,
            'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
            'is_active' => true,
            'approval_status' => CompanyApprovalStatus::Approved,
            'approved_at' => now(),
            'approval_note' => 'Migrated from MVAS .dump (clients.id='.$client['id'].').',
            'created_by_customer_id' => null,
            'legacy_mvas_client_id' => $client['id'],
        ]);

        $stats['companies']['imported']++;

        return (int) $company->id;
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<int, int>  $customerByClient
     * @param  array<string, true>  $usedPhones
     * @param  array<string, true>  $usedEmails
     * @param  array<string, mixed>  $stats
     */
    private function upsertCustomer(
        array $client,
        string $companyName,
        string $phone,
        bool $dryRun,
        array $customerByClient,
        array &$usedPhones,
        array &$usedEmails,
        array &$stats,
    ): ?int {
        if (isset($customerByClient[$client['id']])) {
            $stats['customers']['skipped']++;

            return $customerByClient[$client['id']];
        }

        $existing = Customer::query()
            ->where('legacy_mvas_client_id', $client['id'])
            ->orWhere('sub', 'mvas-client-'.$client['id'])
            ->first();

        if ($existing) {
            $stats['customers']['skipped']++;

            return (int) $existing->id;
        }

        $safePhone = ($phone !== '' && ! isset($usedPhones[$phone])) ? $phone : null;
        $email = trim((string) ($client['email'] ?? ''));
        $safeEmail = ($email !== '' && ! isset($usedEmails[strtolower($email)])) ? $email : null;

        if ($dryRun) {
            if ($safePhone !== null) {
                $usedPhones[$safePhone] = true;
            }
            if ($safeEmail !== null) {
                $usedEmails[strtolower($safeEmail)] = true;
            }
            $stats['customers']['imported']++;

            return null;
        }

        $customer = new Customer;
        $customer->syncFromFayda([
            'sub' => 'mvas-client-'.$client['id'],
            'name' => trim((string) ($client['name'] ?: $companyName)) ?: 'Migrated partner',
            'phone_number' => $safePhone,
            'email' => $safeEmail,
            'identification_type' => '2',
            'identification_number' => 'mvas-client-'.$client['id'],
        ]);

        // No company_* / membership yet — Fayda login claims matching company by phone
        // (or admin assigns orphan companies after verification).
        $customer->forceFill([
            'is_active' => (bool) $client['is_active'],
            'is_banned' => (bool) $client['is_banned'],
            'company_name' => null,
            'company_tin' => null,
            'company_license_number' => null,
            'company_phone' => null,
            'company_email' => null,
            'company_address' => null,
            'current_company_id' => null,
            'profile_completed_at' => null,
            'legacy_mvas_client_id' => $client['id'],
        ])->save();

        if ($safePhone !== null) {
            $usedPhones[$safePhone] = true;
        }
        if ($safeEmail !== null) {
            $usedEmails[strtolower($safeEmail)] = true;
        }

        $stats['customers']['imported']++;

        return (int) $customer->id;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function linkOwnerMembership(int $customerId, int $companyId, array &$stats): void
    {
        $company = Company::query()->find($companyId);
        if (! $company) {
            $stats['memberships']['skipped']++;

            return;
        }

        // Only auto-link when company is still ownerless (pilot claim path).
        $hasOwner = CompanyMembership::query()
            ->where('company_id', $companyId)
            ->where('role', CompanyRole::Owner->value)
            ->exists();

        if ($hasOwner) {
            $stats['memberships']['skipped']++;

            return;
        }

        CompanyMembership::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'customer_id' => $customerId,
            ],
            [
                'role' => CompanyRole::Owner,
                'is_active' => true,
            ],
        );

        Customer::query()->whereKey($customerId)->update(['current_company_id' => $companyId]);
        $stats['memberships']['linked']++;
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>|null
     */
    private function mapTicketRow(array $row): ?array
    {
        // CREATE TABLE `tickets` column order in mvas_*.dump
        if (count($row) < 33) {
            return null;
        }

        $id = (int) $row[0];
        $clientId = (int) ($row[2] ?? 0);
        $ttNumber = trim((string) ($row[15] ?? ''));
        if ($id < 1 || $clientId < 1 || $ttNumber === '') {
            return null;
        }

        $description = $row[32] ?? $row[23] ?? null;
        if (is_string($description)) {
            $description = Str::limit(trim($description), 65000, '');
        }

        return [
            'id' => $id,
            'client_id' => $clientId,
            'service_id' => (int) ($row[5] ?? 0),
            'requisition_id' => (int) ($row[6] ?? 0),
            'status_id' => (int) ($row[10] ?? 1),
            'priority_id' => $row[11] !== null ? (int) $row[11] : null,
            'category_id' => (int) ($row[12] ?? 0),
            'tt_number' => $ttNumber,
            'escalated_at' => $row[16],
            'rejected_at' => $row[17],
            'closed_at' => $row[18],
            'building' => $row[19],
            'location' => $row[20],
            'deleted_at' => $row[27],
            'description' => $description,
            'created_at' => $row[25],
            'updated_at' => $row[26],
        ];
    }

    /**
     * Build legacy_id → local id maps from seeded catalog (mvas_catalog.json + DB slugs).
     *
     * @return array{services: array<int, int>, requisitions: array<int, int>, categories: array<int, int>}
     */
    private function buildCatalogMaps(): array
    {
        $path = database_path('data/mvas_catalog.json');
        $data = File::exists($path)
            ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)
            : [];

        $categoryBySlug = Category::query()->pluck('id', 'slug')->all();
        $serviceBySlug = Service::query()->pluck('id', 'slug')->all();
        $requisitionBySlug = Requisition::query()->pluck('id', 'slug')->all();

        $categories = [];
        foreach ($data['categories'] ?? [] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '' && isset($categoryBySlug[$slug])) {
                $categories[(int) $row['legacy_id']] = (int) $categoryBySlug[$slug];
            }
        }

        $services = [];
        foreach ($data['services'] ?? [] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '' && isset($serviceBySlug[$slug])) {
                $services[(int) $row['legacy_id']] = (int) $serviceBySlug[$slug];
            }
        }

        $requisitions = [];
        foreach ($data['requisitions'] ?? [] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '' && isset($requisitionBySlug[$slug])) {
                $requisitions[(int) $row['legacy_id']] = (int) $requisitionBySlug[$slug];
            }
        }

        // Fallback: identity map when local ids still match legacy (fresh seed).
        if ($services === []) {
            foreach (Service::query()->pluck('id') as $id) {
                $services[(int) $id] = (int) $id;
            }
        }
        if ($requisitions === []) {
            foreach (Requisition::query()->pluck('id') as $id) {
                $requisitions[(int) $id] = (int) $id;
            }
        }
        if ($categories === []) {
            foreach (Category::query()->pluck('id') as $id) {
                $categories[(int) $id] = (int) $id;
            }
        }

        return compact('services', 'requisitions', 'categories');
    }
}
