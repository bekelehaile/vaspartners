<?php

namespace App\Services\Migration;

use App\Enums\RenewalInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceFinalApprover;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Support\Migration\MvasDumpTableReader;
use App\Support\Migration\MvasStaffLegacyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enrich migrated MVAS data: subscriptions, manage-ticket links, final approvers, attachments.
 */
class MvasDumpEnrichmentService
{
    public function __construct(
        private readonly MvasDumpTableReader $tableReader,
    ) {}

    /**
     * @param  array{
     *   dump: string,
     *   storage?: string|null,
     *   dry_run?: bool,
     *   skip_subscriptions?: bool,
     *   skip_approvers?: bool,
     *   skip_attachments?: bool,
     *   attachment_limit?: int|null
     * }  $options
     * @return array<string, mixed>
     */
    public function enrich(array $options): array
    {
        $dump = $options['dump'];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $storage = $options['storage'] ?? env('MVAS_STORAGE_PATH');
        $storage = is_string($storage) && $storage !== '' ? rtrim($storage, '/') : null;

        $stats = [
            'subscriptions' => ['imported' => 0, 'skipped' => 0, 'terminated' => 0, 'linked_tickets' => 0],
            'approvers' => ['imported' => 0, 'skipped' => 0],
            'attachments' => ['imported' => 0, 'skipped' => 0, 'missing_file' => 0, 'unmapped_type' => 0, 'orphaned' => 0],
            'dry_run' => $dryRun,
        ];

        $catalog = $this->buildCatalogMaps();
        $userByLegacy = $this->buildUserLegacyMap();

        if (! ($options['skip_subscriptions'] ?? false)) {
            $this->importSubscriptions($dryRun, $stats);
        }

        if (! ($options['skip_approvers'] ?? false)) {
            $this->importApprovers($dump, $catalog, $userByLegacy, $dryRun, $stats);
        }

        if (! ($options['skip_attachments'] ?? false)) {
            if ($storage === null || ! is_dir($storage)) {
                Log::warning('MVAS attachment import skipped — set MVAS_STORAGE_PATH or --storage to old storage/app');
                $stats['attachments']['note'] = 'storage_path_missing';
            } else {
                $limit = isset($options['attachment_limit']) ? (int) $options['attachment_limit'] : null;
                $this->importAttachments($dump, $storage, $dryRun, $limit, $stats);
            }
        }

        Log::info('MVAS dump enrichment finished', $stats);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function importSubscriptions(bool $dryRun, array &$stats): void
    {
        $createReqIds = Requisition::query()
            ->where('creates_subscription', true)
            ->pluck('id')
            ->all();
        $terminateReqIds = Requisition::query()
            ->where('terminates_subscription', true)
            ->pluck('id')
            ->all();

        if ($createReqIds === []) {
            return;
        }

        $doneStatuses = [TicketStatus::Completed->value, TicketStatus::Closed->value];

        $activationTickets = Ticket::query()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->whereIn('requisition_id', $createReqIds)
            ->whereIn('status', $doneStatuses)
            ->orderBy('id')
            ->get();

        /** @var array<string, Subscription> */
        $aliveByKey = [];

        foreach ($activationTickets as $ticket) {
            $customer = Customer::query()->find($ticket->customer_id);
            if (! $customer?->legacy_mvas_client_id) {
                $stats['subscriptions']['skipped']++;

                continue;
            }

            $company = Company::query()
                ->where('legacy_mvas_client_id', $customer->legacy_mvas_client_id)
                ->first();
            if (! $company) {
                $stats['subscriptions']['skipped']++;

                continue;
            }

            $key = $company->id.':'.$ticket->service_id;
            if (isset($aliveByKey[$key])) {
                // Keep first (oldest) activation; still link later tickets to it.
                if (! $dryRun && ! $ticket->subscription_id) {
                    $ticket->forceFill(['subscription_id' => $aliveByKey[$key]->id])->save();
                    $stats['subscriptions']['linked_tickets']++;
                }
                $stats['subscriptions']['skipped']++;

                continue;
            }

            $existing = Subscription::query()
                ->where('company_id', $company->id)
                ->where('service_id', $ticket->service_id)
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PendingRenewal->value,
                    SubscriptionStatus::Grace->value,
                ])
                ->first();

            if ($existing) {
                $aliveByKey[$key] = $existing;
                if (! $dryRun && ! $ticket->subscription_id) {
                    $ticket->forceFill(['subscription_id' => $existing->id])->save();
                    $stats['subscriptions']['linked_tickets']++;
                }
                $stats['subscriptions']['skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats['subscriptions']['imported']++;
                $aliveByKey[$key] = new Subscription(['id' => 0]);

                continue;
            }

            $service = Service::query()->find($ticket->service_id);
            $interval = $service?->renewal_interval instanceof RenewalInterval
                ? $service->renewal_interval
                : RenewalInterval::Yearly;
            $started = $ticket->completed_at ?? $ticket->closed_at ?? $ticket->created_at ?? now();
            $months = $interval->months();
            $periodEnd = (clone $started)->addMonthsNoOverflow($months);

            $subscription = Subscription::query()->create([
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'service_id' => $ticket->service_id,
                'status' => SubscriptionStatus::Active,
                'renewal_interval' => $interval,
                'started_at' => $started,
                'current_period_start' => $started,
                'current_period_end' => $periodEnd,
                'next_renewal_due_at' => $periodEnd,
                'activated_by_ticket_id' => $ticket->id,
                'legacy_mvas_client_id' => $customer->legacy_mvas_client_id,
                'legacy_mvas_service_id' => null,
            ]);

            $ticket->forceFill(['subscription_id' => $subscription->id])->save();
            $aliveByKey[$key] = $subscription;
            $stats['subscriptions']['imported']++;
            $stats['subscriptions']['linked_tickets']++;
        }

        // Link manage tickets to alive subscription for same company+service.
        $manageTickets = Ticket::query()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->whereNull('subscription_id')
            ->whereNotIn('requisition_id', $createReqIds)
            ->get();

        foreach ($manageTickets as $ticket) {
            $customer = Customer::query()->find($ticket->customer_id);
            if (! $customer?->legacy_mvas_client_id) {
                continue;
            }
            $company = Company::query()
                ->where('legacy_mvas_client_id', $customer->legacy_mvas_client_id)
                ->first();
            if (! $company) {
                continue;
            }
            $key = $company->id.':'.$ticket->service_id;
            $sub = $aliveByKey[$key] ?? Subscription::query()
                ->where('company_id', $company->id)
                ->where('service_id', $ticket->service_id)
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PendingRenewal->value,
                    SubscriptionStatus::Grace->value,
                ])
                ->first();

            if (! $sub || (int) ($sub->id ?? 0) < 1) {
                continue;
            }

            if (! $dryRun) {
                $ticket->forceFill(['subscription_id' => $sub->id])->save();
            }
            $stats['subscriptions']['linked_tickets']++;
        }

        // Apply terminations.
        if ($terminateReqIds !== []) {
            $termTickets = Ticket::query()
                ->whereNotNull('legacy_mvas_ticket_id')
                ->whereIn('requisition_id', $terminateReqIds)
                ->whereIn('status', $doneStatuses)
                ->get();

            foreach ($termTickets as $ticket) {
                $customer = Customer::query()->find($ticket->customer_id);
                if (! $customer?->legacy_mvas_client_id) {
                    continue;
                }
                $company = Company::query()
                    ->where('legacy_mvas_client_id', $customer->legacy_mvas_client_id)
                    ->first();
                if (! $company) {
                    continue;
                }

                $sub = Subscription::query()
                    ->where('company_id', $company->id)
                    ->where('service_id', $ticket->service_id)
                    ->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PendingRenewal->value,
                        SubscriptionStatus::Grace->value,
                    ])
                    ->first();

                if (! $sub) {
                    continue;
                }

                if (! $dryRun) {
                    $sub->forceFill([
                        'status' => SubscriptionStatus::Terminated,
                        'terminated_at' => $ticket->closed_at ?? $ticket->completed_at ?? now(),
                        'terminated_by_ticket_id' => $ticket->id,
                    ])->save();
                    $ticket->forceFill(['subscription_id' => $sub->id])->save();
                }
                $stats['subscriptions']['terminated']++;
            }
        }
    }

    /**
     * @param  array{services: array<int, int>, requisitions: array<int, int>}  $catalog
     * @param  array<int, int>  $userByLegacy
     * @param  array<string, mixed>  $stats
     */
    private function importApprovers(
        string $dump,
        array $catalog,
        array $userByLegacy,
        bool $dryRun,
        array &$stats,
    ): void {
        foreach ($this->tableReader->rows($dump, 'service_approvers') as $row) {
            if (count($row) < 5) {
                continue;
            }
            // Soft-deleted
            if (($row[8] ?? null) !== null) {
                continue;
            }

            $legacyServiceId = (int) $row[1];
            $serviceId = $catalog['services'][$legacyServiceId] ?? null;
            if (! $serviceId) {
                $stats['approvers']['skipped']++;

                continue;
            }

            $requisitionIds = $this->parseJsonIdList($row[2] ?? '[]');
            $approverLegacyIds = $this->parseJsonIdList($row[3] ?? '[]');
            if ($requisitionIds === [] || $approverLegacyIds === []) {
                $stats['approvers']['skipped']++;

                continue;
            }

            foreach ($requisitionIds as $legacyReqId) {
                $requisitionId = $catalog['requisitions'][$legacyReqId] ?? null;
                if (! $requisitionId) {
                    continue;
                }

                foreach ($approverLegacyIds as $legacyUserId) {
                    $userId = $userByLegacy[$legacyUserId] ?? null;
                    if (! $userId) {
                        $stats['approvers']['skipped']++;

                        continue;
                    }

                    $exists = ServiceFinalApprover::query()
                        ->where('service_id', $serviceId)
                        ->where('requisition_id', $requisitionId)
                        ->where('user_id', $userId)
                        ->exists();

                    if ($exists) {
                        $stats['approvers']['skipped']++;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['approvers']['imported']++;

                        continue;
                    }

                    ServiceFinalApprover::query()->create([
                        'service_id' => $serviceId,
                        'requisition_id' => $requisitionId,
                        'user_id' => $userId,
                    ]);
                    $stats['approvers']['imported']++;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function importAttachments(
        string $dump,
        string $storageRoot,
        bool $dryRun,
        ?int $limit,
        array &$stats,
    ): void {
        /** @var array<int, array{path: string, mime: ?string, name: string, size: int}> */
        $files = [];
        foreach ($this->tableReader->rows($dump, 'files') as $row) {
            if (count($row) < 9) {
                continue;
            }
            if (($row[18] ?? null) !== null) {
                continue; // deleted
            }
            $id = (int) $row[0];
            $rel = (string) ($row[4] ?? '');
            if ($id < 1 || $rel === '') {
                continue;
            }
            $absolute = $storageRoot.'/'.ltrim($rel, '/');
            $files[$id] = [
                'path' => $absolute,
                'mime' => $row[5],
                'name' => (string) ($row[8] ?: basename($rel)),
                'size' => is_file($absolute) ? (int) filesize($absolute) : 0,
            ];
        }

        $docTypesByName = $this->documentTypesByNormalizedName();
        $fallbackTypeId = DocumentType::query()->where('code', 'document-if-any')->value('id')
            ?? DocumentType::query()->orderBy('id')->value('id');

        $ticketsByLegacy = Ticket::query()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->get(['id', 'public_id', 'legacy_mvas_ticket_id', 'customer_id'])
            ->keyBy(fn (Ticket $t) => (int) $t->legacy_mvas_ticket_id);

        $seen = 0;
        foreach ($this->tableReader->rows($dump, 'fileables') as $row) {
            if (count($row) < 10) {
                continue;
            }
            if (($row[10] ?? null) !== null) {
                continue;
            }

            $type = (string) ($row[3] ?? '');
            if (! str_contains($type, 'Ticket')) {
                continue;
            }

            if ($limit !== null && $seen >= $limit) {
                break;
            }
            $seen++;

            $legacyTicketId = (int) ($row[4] ?? 0);
            $fileId = (int) ($row[2] ?? 0);
            $requested = trim((string) ($row[5] ?? ''));
            $verified = (string) ($row[7] ?? '0') === '1';

            $ticket = $ticketsByLegacy->get($legacyTicketId);
            if (! $ticket) {
                $stats['attachments']['orphaned']++;

                continue;
            }

            if (TicketDocument::query()->where('legacy_mvas_file_id', $fileId)->exists()) {
                $stats['attachments']['skipped']++;

                continue;
            }

            $file = $files[$fileId] ?? null;
            if (! $file || ! is_file($file['path'])) {
                $stats['attachments']['missing_file']++;

                continue;
            }

            $matchedTypeId = $this->matchDocumentTypeId($requested, $docTypesByName);
            $docTypeId = $matchedTypeId ?? $fallbackTypeId;
            if (! $docTypeId) {
                $stats['attachments']['unmapped_type']++;

                continue;
            }
            if ($matchedTypeId === null) {
                $stats['attachments']['unmapped_type']++;
            }

            // One row per ticket+document_type in new platform — keep first file for that type.
            if (TicketDocument::query()
                ->where('ticket_id', $ticket->id)
                ->where('document_type_id', $docTypeId)
                ->exists()) {
                $stats['attachments']['skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats['attachments']['imported']++;

                continue;
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
            $destRel = 'tickets/'.$ticket->public_id.'/'.Str::uuid().'.'.$ext;
            $destAbs = storage_path('app/private/'.$destRel);
            File::ensureDirectoryExists(dirname($destAbs));
            File::copy($file['path'], $destAbs);

            TicketDocument::query()->create([
                'ticket_id' => $ticket->id,
                'document_type_id' => $docTypeId,
                'disk' => 'local',
                'path' => $destRel,
                'original_name' => $file['name'],
                'mime_type' => $file['mime'],
                'size_bytes' => $file['size'] ?: (int) filesize($destAbs),
                'verification_status' => $verified ? 'accepted' : 'pending',
                'remark' => $requested !== '' ? 'Migrated from MVAS: '.$requested : 'Migrated from MVAS',
                'uploaded_by_customer_id' => $ticket->customer_id,
                'legacy_mvas_file_id' => $fileId,
            ]);

            $stats['attachments']['imported']++;
        }
    }

    /**
     * @return array<int, int> legacy user id → local user id
     */
    private function buildUserLegacyMap(): array
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

    /**
     * @return array{services: array<int, int>, requisitions: array<int, int>}
     */
    private function buildCatalogMaps(): array
    {
        $path = database_path('data/mvas_catalog.json');
        $data = File::exists($path)
            ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)
            : [];

        $serviceBySlug = Service::query()->pluck('id', 'slug')->all();
        $requisitionBySlug = Requisition::query()->pluck('id', 'slug')->all();

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

        return compact('services', 'requisitions');
    }

    /**
     * @return array<string, int>
     */
    private function documentTypesByNormalizedName(): array
    {
        $map = [];
        foreach (DocumentType::query()->get(['id', 'name', 'code']) as $type) {
            $map[$this->normalizeDocName($type->name)] = (int) $type->id;
            $map[$this->normalizeDocName(str_replace('-', ' ', (string) $type->code))] = (int) $type->id;
        }

        // Common MVAS label aliases → catalog names.
        $aliases = [
            'commercial registration' => 'commercial registration',
            'renewed or new trade license' => 'renewed or new trade license',
            'well developed proposal' => 'well developed proposal',
            'tin number' => 'tin number',
            'house rent or proof of ownership' => 'house rent or proof of ownership',
            'document if any' => 'document if any',
        ];
        foreach ($aliases as $from => $to) {
            if (isset($map[$to])) {
                $map[$from] = $map[$to];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $byName
     */
    private function matchDocumentTypeId(string $requested, array $byName): ?int
    {
        $norm = $this->normalizeDocName($requested);
        if ($norm === '') {
            return null;
        }
        if (isset($byName[$norm])) {
            return $byName[$norm];
        }

        // Prefix / contains soft match.
        foreach ($byName as $name => $id) {
            if ($name !== '' && (str_contains($norm, $name) || str_contains($name, $norm))) {
                return $id;
            }
        }

        return null;
    }

    private function normalizeDocName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = str_replace(['_', '/'], ' ', $name);

        return trim($name);
    }

    /**
     * @return list<int>
     */
    private function parseJsonIdList(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === 'null') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $decoded)));
    }
}
