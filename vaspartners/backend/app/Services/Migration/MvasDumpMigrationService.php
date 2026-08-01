<?php

namespace App\Services\Migration;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Contact;
use App\Models\Priority;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SmsService;
use App\Support\Migration\MvasDumpPartnerReader;
use App\Support\Migration\MvasDumpTableReader;
use App\Support\Migration\MvasStaffLegacyMap;
use App\Support\TimestampPublicId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Full MVAS `.dump` → VAS Partners seed for companies, contacts, and tickets.
 */
class MvasDumpMigrationService
{
    /** @var array<int, TicketStatus> */
    private const STATUS_MAP = [
        1 => TicketStatus::Open,
        2 => TicketStatus::InProgress,
        // Old MVAS "Completed" / "Approved" → Closed (finished service requests).
        3 => TicketStatus::Closed,
        4 => TicketStatus::Closed,
        5 => TicketStatus::Rejected,
        6 => TicketStatus::Closed,
    ];

    public function __construct(
        private readonly MvasDumpPartnerReader $partnerReader,
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
     *   legacy_ids?: list<int>,
     *   skip_companies?: bool,
     *   skip_contacts?: bool,
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
        $onlyLegacyIds = array_values(array_filter(array_map('intval', $options['legacy_ids'] ?? [])));
        // Default false: companies stay ownerless until Fayda phone-claim or admin assign.
        $linkMemberships = (bool) ($options['link_memberships'] ?? false);

        $catalog = $this->buildCatalogMaps();
        $userByLegacy = $this->buildStaffUserLegacyMap();
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
            'contacts' => ['imported' => 0, 'skipped' => 0, 'selected' => 0, 'portal_activated' => 0],
            'tickets' => ['imported' => 0, 'skipped' => 0, 'selected' => 0, 'orphaned' => 0, 'assigned' => 0, 'unmapped_assignee' => 0],
            'memberships' => ['linked' => 0, 'skipped' => 0],
            'dry_run' => $dryRun,
        ];

        /** @var array<int, int> legacy client id → company id */
        $companyByLegacyId = [];
        /** @var array<int, int> legacy client id → contact id */
        $contactByLegacyId = [];
        /** @var array<string, true> */
        $usedPhones = [];
        /** @var array<string, true> */
        $usedEmails = [];

        // Preload existing unique phones/emails so we do not collide.
        foreach (Contact::query()->whereNotNull('phone_number')->pluck('phone_number') as $phone) {
            $usedPhones[(string) $phone] = true;
        }
        foreach (Contact::query()->whereNotNull('email')->pluck('email') as $email) {
            $usedEmails[strtolower((string) $email)] = true;
        }

        foreach (Company::query()->whereNotNull('legacy_mvas_id')->get(['id', 'legacy_mvas_id']) as $company) {
            $companyByLegacyId[(int) $company->legacy_mvas_id] = (int) $company->id;
        }
        foreach (Contact::query()->whereNotNull('legacy_mvas_id')->get(['id', 'legacy_mvas_id']) as $contact) {
            $contactByLegacyId[(int) $contact->legacy_mvas_id] = (int) $contact->id;
        }

        if (! ($options['skip_companies'] ?? false) || ! ($options['skip_contacts'] ?? false)) {
            $partnersSelected = 0;

            foreach ($this->partnerReader->partners($dump) as $partner) {
                if ($onlyLegacyIds !== [] && ! in_array($partner['id'], $onlyLegacyIds, true)) {
                    continue;
                }
                if ($partner['deleted_at'] !== null) {
                    continue;
                }
                if ($partner['is_banned']) {
                    // Legacy MVAS ban → skip; portal uses is_active only (no ban column).
                    continue;
                }
                if ($onlyVerified && ! $partner['is_verified_partner']) {
                    continue;
                }

                $companyName = trim((string) ($partner['company_name'] ?: $partner['name']));
                if ($companyName === '') {
                    continue;
                }
                if (! $includeEthio && strcasecmp($companyName, 'ethio telecom') === 0) {
                    continue;
                }

                $phone = $this->sms->normalizePhone($partner['phone']);
                if ($phone === '' || ! preg_match('/^\d{9}$/', $phone)) {
                    $phone = '';
                }

                if ($companyLimit !== null && $partnersSelected >= $companyLimit) {
                    break;
                }
                $partnersSelected++;

                if (! ($options['skip_companies'] ?? false)) {
                    $stats['companies']['selected']++;
                    $companyId = $this->upsertCompany($partner, $companyName, $phone, $dryRun, $companyByLegacyId, $stats);
                    if ($companyId !== null) {
                        $companyByLegacyId[$partner['id']] = $companyId;
                    }
                }

                if (! ($options['skip_contacts'] ?? false)) {
                    $stats['contacts']['selected']++;
                    $contactId = $this->upsertContact(
                        $partner,
                        $companyName,
                        $phone,
                        $dryRun,
                        $contactByLegacyId,
                        $usedPhones,
                        $usedEmails,
                        $stats,
                    );
                    if ($contactId !== null) {
                        $contactByLegacyId[$partner['id']] = $contactId;
                    }

                    if (
                        $linkMemberships
                        && ! $dryRun
                        && isset($companyByLegacyId[$partner['id']], $contactByLegacyId[$partner['id']])
                    ) {
                        $this->linkOwnerMembership(
                            $contactByLegacyId[$partner['id']],
                            $companyByLegacyId[$partner['id']],
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
                if ($onlyLegacyIds !== [] && ! in_array($ticket['legacy_partner_id'], $onlyLegacyIds, true)) {
                    continue;
                }
                if ($companyLimit !== null && ! isset($contactByLegacyId[$ticket['legacy_partner_id']])) {
                    // When limiting companies, only import tickets for imported clients.
                    // Still allow tickets whose contact was already in DB from a prior run.
                    $existingContactId = Contact::query()
                        ->where('legacy_mvas_id', $ticket['legacy_partner_id'])
                        ->value('id');
                    if (! $existingContactId) {
                        continue;
                    }
                    $contactByLegacyId[$ticket['legacy_partner_id']] = (int) $existingContactId;
                }

                if ($ticketLimit !== null && $ticketsSeen >= $ticketLimit) {
                    break;
                }
                $ticketsSeen++;
                $stats['tickets']['selected']++;

                $contactId = $contactByLegacyId[$ticket['legacy_partner_id']] ?? null;
                if ($contactId === null) {
                    $stats['tickets']['orphaned']++;

                    continue;
                }

                $serviceId = $catalog['services'][$ticket['service_id']] ?? $fallbackServiceId;
                $requisitionId = $catalog['requisitions'][$ticket['requisition_id']] ?? $fallbackRequisitionId;
                $categoryId = $catalog['categories'][$ticket['category_id']]
                    ?? (int) (Service::query()->whereKey($serviceId)->value('category_id') ?: $fallbackCategoryId);

                $status = self::STATUS_MAP[$ticket['status_id']] ?? TicketStatus::Open;
                $priorityId = $defaultPriorityId ? (int) $defaultPriorityId : null;

                if (Ticket::query()->where('legacy_mvas_ticket_id', $ticket['id'])->exists()) {
                    $stats['tickets']['skipped']++;

                    continue;
                }

                $legacyAssigneeId = $ticket['assigned_to_user_id'] ?? $ticket['handler_user_id'] ?? null;
                $assigneeUserId = $legacyAssigneeId !== null
                    ? ($userByLegacy[$legacyAssigneeId] ?? null)
                    : null;
                if ($legacyAssigneeId !== null && $assigneeUserId === null) {
                    $stats['tickets']['unmapped_assignee']++;
                }

                if ($dryRun) {
                    $stats['tickets']['imported']++;
                    if ($assigneeUserId !== null) {
                        $stats['tickets']['assigned']++;
                    }

                    continue;
                }

                DB::transaction(function () use (
                    $ticket,
                    $contactId,
                    $serviceId,
                    $requisitionId,
                    $categoryId,
                    $status,
                    $priorityId,
                    $assigneeUserId,
                ): void {
                    $createdAt = $ticket['created_at'] ?? now();
                    // Map old MVAS hex ids (e.g. 11B4BA6760) → YmdH + 2 random digits from created_at.
                    $ttNumber = TimestampPublicId::generate(
                        $createdAt,
                        fn (string $number): bool => Ticket::query()->where('tt_number', $number)->exists(),
                    );

                    $finishedAt = $ticket['closed_at'] ?? $ticket['updated_at'] ?? now();
                    $model = new Ticket([
                        'tt_number' => $ttNumber,
                        'legacy_mvas_ticket_id' => $ticket['id'],
                        'contact_id' => $contactId,
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
                        'closed_at' => $status === TicketStatus::Closed
                            ? ($ticket['closed_at'] ?? $finishedAt)
                            : $ticket['closed_at'],
                        'completed_at' => $status === TicketStatus::Closed
                            ? $finishedAt
                            : null,
                        'assigned_to_user_id' => $assigneeUserId,
                        'assigned_at' => $assigneeUserId !== null
                            ? ($ticket['updated_at'] ?? $ticket['created_at'] ?? now())
                            : null,
                    ]);
                    $model->created_at = $createdAt;
                    $model->updated_at = $ticket['updated_at'] ?? now();
                    $model->save();
                });

                $stats['tickets']['imported']++;
                if ($assigneeUserId !== null) {
                    $stats['tickets']['assigned']++;
                }
            }
        }

        // Old MVAS clients.is_active defaults to 0; portal OTP/Fayda require is_active.
        // Always re-enable migrated contacts (idempotent re-runs + legacy imports).
        // Banned MVAS clients are never imported (skipped above).
        $stats['contacts']['portal_activated'] = (int) ($stats['contacts']['portal_activated'] ?? 0)
            + $this->ensureMigratedContactsPortalReady($dryRun);

        Log::info('MVAS dump migration finished', $stats);

        return $stats;
    }

    /**
     * Guarantee migrated partners can pass portal sign-in checks (is_active).
     * Banned legacy clients are never imported. Re-runs re-enable inactive migrated contacts
     * (old MVAS is_active defaults were unreliable).
     */
    public function ensureMigratedContactsPortalReady(bool $dryRun = false): int
    {
        $query = Contact::query()
            ->whereNotNull('legacy_mvas_id')
            ->where('is_active', false);

        if ($dryRun) {
            return (int) $query->count();
        }

        return (int) $query->update(['is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $partner
     * @param  array<int, int>  $companyByLegacyId
     * @param  array<string, mixed>  $stats
     */
    private function upsertCompany(
        array $partner,
        string $companyName,
        string $phone,
        bool $dryRun,
        array $companyByLegacyId,
        array &$stats,
    ): ?int {
        if (isset($companyByLegacyId[$partner['id']])) {
            $stats['companies']['skipped']++;

            return $companyByLegacyId[$partner['id']];
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
            if ($existing->legacy_mvas_id === null && ! $dryRun) {
                $existing->forceFill(['legacy_mvas_id' => $partner['id']])->save();
            }
            $stats['companies']['skipped']++;

            return (int) $existing->id;
        }

        if ($dryRun) {
            $stats['companies']['imported']++;

            return null;
        }

        $addressParts = array_filter([
            trim((string) ($partner['address'] ?? '')),
            trim((string) ($partner['city'] ?? '')),
            trim((string) ($partner['country'] ?? '')),
        ]);

        $company = Company::query()->create([
            'name' => $companyName,
            'tin' => 'MVAS-'.$partner['id'],
            'phone' => $phone !== '' ? $phone : null,
            'claim_phone' => $phone !== '' ? $phone : null,
            'revenue_phone' => $phone !== '' ? $phone : null,
            'email' => $partner['email'] ?: null,
            'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
            'is_active' => true,
            'approval_status' => CompanyApprovalStatus::Approved,
            'approved_at' => now(),
            'approval_note' => 'Migrated from MVAS .dump (clients.id='.$partner['id'].').',
            'created_by_contact_id' => null,
            'legacy_mvas_id' => $partner['id'],
        ]);

        $stats['companies']['imported']++;

        return (int) $company->id;
    }

    /**
     * @param  array<string, mixed>  $partner
     * @param  array<int, int>  $contactByLegacyId
     * @param  array<string, true>  $usedPhones
     * @param  array<string, true>  $usedEmails
     * @param  array<string, mixed>  $stats
     */
    private function upsertContact(
        array $partner,
        string $companyName,
        string $phone,
        bool $dryRun,
        array $contactByLegacyId,
        array &$usedPhones,
        array &$usedEmails,
        array &$stats,
    ): ?int {
        if (isset($contactByLegacyId[$partner['id']])) {
            $stats['contacts']['skipped']++;
            if (! $dryRun) {
                $this->ensureContactPortalReady((int) $contactByLegacyId[$partner['id']], $stats);
            }

            return $contactByLegacyId[$partner['id']];
        }

        $existing = Contact::query()
            ->where('legacy_mvas_id', $partner['id'])
            ->orWhere('sub', 'mvas-contact-'.$partner['id'])
            ->first();

        if ($existing) {
            $stats['contacts']['skipped']++;
            if (! $dryRun) {
                $this->ensureContactPortalReady((int) $existing->id, $stats);
            }

            return (int) $existing->id;
        }

        $safePhone = ($phone !== '' && ! isset($usedPhones[$phone])) ? $phone : null;
        $email = trim((string) ($partner['email'] ?? ''));
        $safeEmail = ($email !== '' && ! isset($usedEmails[strtolower($email)])) ? $email : null;

        if ($dryRun) {
            if ($safePhone !== null) {
                $usedPhones[$safePhone] = true;
            }
            if ($safeEmail !== null) {
                $usedEmails[strtolower($safeEmail)] = true;
            }
            $stats['contacts']['imported']++;

            return null;
        }

        $contact = new Contact;
        $contact->syncFromFayda([
            'sub' => 'mvas-contact-'.$partner['id'],
            'name' => trim((string) ($partner['name'] ?: $companyName)) ?: 'Migrated partner',
            'phone_number' => $safePhone,
            'email' => $safeEmail,
            'identification_type' => '2',
            'identification_number' => 'mvas-contact-'.$partner['id'],
        ]);

        // No company_* / membership yet — Fayda login claims matching company by phone
        // (or admin assigns orphan companies after verification).
        // Old MVAS `clients.is_active` defaults to 0 and is rarely flipped; verified
        // partners still signed in there. Portal requires is_active — always enable
        // migrated contacts (banned clients are skipped above).
        $contact->forceFill([
            'is_active' => true,
            'company_name' => null,
            'company_tin' => null,
            'company_phone' => null,
            'company_email' => null,
            'company_address' => null,
            'current_company_id' => null,
            'profile_completed_at' => null,
            'legacy_mvas_id' => $partner['id'],
        ])->save();

        if ($safePhone !== null) {
            $usedPhones[$safePhone] = true;
        }
        if ($safeEmail !== null) {
            $usedEmails[strtolower($safeEmail)] = true;
        }

        $stats['contacts']['imported']++;

        return (int) $contact->id;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function ensureContactPortalReady(int $contactId, array &$stats): void
    {
        $updated = Contact::query()
            ->whereKey($contactId)
            ->where('is_active', false)
            ->update(['is_active' => true]);

        if ($updated > 0) {
            $stats['contacts']['portal_activated'] = (int) ($stats['contacts']['portal_activated'] ?? 0) + $updated;
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function linkOwnerMembership(int $contactId, int $companyId, array &$stats): void
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
                'contact_id' => $contactId,
            ],
            [
                'role' => CompanyRole::Owner,
                'is_active' => true,
            ],
        );

        Contact::query()->whereKey($contactId)->update(['current_company_id' => $companyId]);
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
            'legacy_partner_id' => $clientId,
            'service_id' => (int) ($row[5] ?? 0),
            'requisition_id' => (int) ($row[6] ?? 0),
            'status_id' => (int) ($row[10] ?? 1),
            'priority_id' => $row[11] !== null ? (int) $row[11] : null,
            'category_id' => (int) ($row[12] ?? 0),
            'assigned_to_user_id' => $row[13] !== null && $row[13] !== '' ? (int) $row[13] : null,
            'handler_user_id' => $row[14] !== null && $row[14] !== '' ? (int) $row[14] : null,
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

    /**
     * @return array<int, int> legacy MVAS users.id → local users.id
     */
    private function buildStaffUserLegacyMap(): array
    {
        $map = [];
        foreach (MvasStaffLegacyMap::emailsByLegacyId() as $legacyId => $email) {
            $userId = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->value('id');
            if ($userId) {
                $map[$legacyId] = (int) $userId;
            }
        }

        return $map;
    }
}
