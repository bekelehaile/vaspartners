<?php

namespace App\Services;

use App\Enums\BulkMessageRecipientStatus;
use App\Enums\BulkMessageStatus;
use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Jobs\SendBulkMessageRecipientJob;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Models\AppSetting;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\RevenueDuplicatePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Post-import actions for monthly revenue (match / register / send).
 * CSV ingest uses Filament ImportAction + MonthlyRevenueImporter.
 */
class RevenueImportService
{
    /** Default SMS body for monthly revenue collection (not Bulk messages). */
    public const DEFAULT_SMS_TEMPLATE = 'Dear {company_name}, your {period}, {service_type} revenue with Service ID {service_id} is ETB {amount}. Please provide the request letter with amount and ref number. Thank You Ethio Telecom';

    public function __construct(
        private readonly BulkMessageService $bulkMessages,
        private readonly RevenuePartnerResolver $partners,
    ) {}

    public function rematch(RevenueImport $import): void
    {
        $ownerUserId = $this->ownerUserIdForImport($import);
        $vasServiceId = (int) $import->vas_service_id;
        $period = (string) $import->period;

        DB::transaction(function () use ($import, $vasServiceId, $ownerUserId, $period): void {
            $amUserId = $import->created_by_user_id ? (int) $import->created_by_user_id : null;
            $seen = [];
            foreach ($import->rows()->with('partner')->orderBy('id')->get() as $row) {
                if ($row->wasSent() || $row->status === RevenueImportRowStatus::Sent) {
                    $this->rememberSeenFingerprint($seen, $row, $import);

                    continue;
                }

                $payload = $this->classify(
                    serviceId: $row->service_id,
                    shortCode: $row->short_code,
                    amount: $row->amount !== null ? (float) $row->amount : null,
                    seen: $seen,
                    ownerUserId: $ownerUserId,
                    period: $period,
                    excludeRowId: (int) $row->id,
                    catalogServiceId: $vasServiceId,
                    amUserId: $amUserId,
                );
                $row->forceFill([
                    'revenue_partner_id' => $payload['revenue_partner_id'],
                    'partner_name' => $payload['partner_name'],
                    'service_id' => $payload['resolved_service_id'] ?? $row->service_id,
                    'short_code' => $payload['resolved_short_code'] ?? $row->short_code,
                    'vas_service_id' => $vasServiceId,
                    'status' => $payload['status'],
                    'error' => $payload['error'],
                    'amount' => $payload['amount'],
                ])->save();
            }
            $import->resolveStatusFromRows();
        });
    }

    /**
     * Re-check rows against the master list by service ID and/or short code
     * and mark matched when the partner now has a usable phone.
     *
     * @param  iterable<int>|null  $rowIds  null = all missing-phone rows on the import
     * @return array{synced: int, still_missing: int, unresolved: int}
     */
    public function syncPhonesFromPartners(RevenueImport $import, ?iterable $rowIds = null): array
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            throw ValidationException::withMessages(['import' => 'This import can no longer be edited.']);
        }

        $ids = $rowIds === null ? null : collect($rowIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        $synced = 0;
        $stillMissing = 0;
        $unresolved = 0;

        DB::transaction(function () use ($import, $ids, &$synced, &$stillMissing, &$unresolved): void {
            $ownerUserId = $this->ownerUserIdForImport($import);
            $vasServiceId = (int) $import->vas_service_id;
            $period = (string) $import->period;

            $query = $import->rows()->orderBy('id');
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            } else {
                $query->where('status', RevenueImportRowStatus::MissingPhone->value);
            }

            $rows = $query->get();
            $targetIds = $rows->pluck('id')->all();

            $amUserId = $import->created_by_user_id ? (int) $import->created_by_user_id : null;
            $seen = [];
            foreach ($import->rows()->with('partner')->whereNotIn('id', $targetIds)->orderBy('id')->get() as $other) {
                $this->rememberSeenFingerprint($seen, $other, $import);
            }

            foreach ($rows as $row) {
                $payload = $this->classify(
                    serviceId: $row->service_id,
                    shortCode: $row->short_code,
                    amount: $row->amount !== null ? (float) $row->amount : null,
                    seen: $seen,
                    ownerUserId: $ownerUserId,
                    period: $period,
                    excludeRowId: (int) $row->id,
                    catalogServiceId: $vasServiceId,
                    amUserId: $amUserId,
                );

                $row->forceFill([
                    'revenue_partner_id' => $payload['revenue_partner_id'],
                    'partner_name' => $payload['partner_name'],
                    'service_id' => $payload['resolved_service_id'] ?? $row->service_id,
                    'short_code' => $payload['resolved_short_code'] ?? $row->short_code,
                    'vas_service_id' => $vasServiceId,
                    'status' => $payload['status'],
                    'error' => $payload['error'],
                    'amount' => $payload['amount'],
                ])->save();

                match ($payload['status']) {
                    RevenueImportRowStatus::Matched => $synced++,
                    RevenueImportRowStatus::MissingPhone => $stillMissing++,
                    RevenueImportRowStatus::MissingPartner => $unresolved++,
                    default => null,
                };
            }

            $import->resolveStatusFromRows();
        });

        return [
            'synced' => $synced,
            'still_missing' => $stillMissing,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Manually set status on one or more import rows, then refresh the import status.
     *
     * @param  iterable<int>  $rowIds
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function setRowStatuses(
        RevenueImport $import,
        iterable $rowIds,
        RevenueImportRowStatus $status,
        ?string $note = null,
    ): array {
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);
        $this->assertImportEditable($import);

        $ids = collect($rowIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $note = filled(trim((string) $note)) ? trim((string) $note) : null;

        DB::transaction(function () use ($import, $ids, $status, $note, &$updated, &$skipped, &$errors): void {
            $ownerUserId = $this->ownerUserIdForImport($import);
            $rows = $import->rows()->with('partner')->whereIn('id', $ids)->orderBy('id')->get();

            foreach ($rows as $row) {
                try {
                    $this->applyManualRowStatus($row, $status, $note, $ownerUserId);
                    $updated++;
                } catch (ValidationException $e) {
                    $skipped++;
                    $errors[] = ($row->service_id ?: $row->short_code ?: "#{$row->id}").': '
                        .(collect($e->errors())->flatten()->first() ?? $e->getMessage());
                }
            }

            $import->resolveStatusFromRows();
        });

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Delete payload rows that have not been sent yet, then refresh import counts/status.
     *
     * @param  iterable<int>  $rowIds
     * @return array{deleted: int, skipped: int, errors: list<string>}
     */
    public function deleteRows(RevenueImport $import, iterable $rowIds, User $actor): array
    {
        $this->assertCanManage($actor, $import);
        $this->assertImportEditable($import);

        $ids = collect($rowIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($import, $ids, &$deleted, &$skipped, &$errors): void {
            $rows = $import->rows()->whereIn('id', $ids)->orderBy('id')->get();

            foreach ($rows as $row) {
                if ($row->wasSent() || $row->status === RevenueImportRowStatus::Sent) {
                    $skipped++;
                    $errors[] = ($row->service_id ?: $row->short_code ?: "#{$row->id}").': already sent';

                    continue;
                }

                $row->delete();
                $deleted++;
            }

            $import->resolveStatusFromRows();
        });

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Manually set the monthly import status (not Sending / Completed).
     */
    public function setImportStatus(RevenueImport $import, RevenueImportStatus $status): void
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);
        $this->assertImportEditable($import);

        if (in_array($status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => 'Sending and Completed are set automatically when SMS is queued.',
            ]);
        }

        if ($status === RevenueImportStatus::Ready) {
            $import->refreshCounts();
            if ($import->matched_count < 1
                || $import->missing_partner_count > 0
                || $import->missing_phone_count > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Import is not Ready yet — fix unresolved / missing-phone rows first.',
                ]);
            }
        }

        $import->forceFill(['status' => $status])->save();
    }

    protected function applyManualRowStatus(
        RevenueImportRow $row,
        RevenueImportRowStatus $status,
        ?string $note,
        ?int $ownerUserId,
    ): void {
        if ($row->wasSent() || $row->status === RevenueImportRowStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'This row was already sent and cannot change status.',
            ]);
        }

        if ($status === RevenueImportRowStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'Use Send SMS to mark a row as Sent.',
            ]);
        }

        $partner = $row->partner;

        $payload = match ($status) {
            RevenueImportRowStatus::Matched => $this->manualMatchedPayload($row, $partner, $ownerUserId),
            RevenueImportRowStatus::MissingPartner => [
                'revenue_partner_id' => null,
                'partner_name' => $row->partner_name,
                'status' => RevenueImportRowStatus::MissingPartner,
                'error' => $note ?? 'Marked unresolved manually.',
            ],
            RevenueImportRowStatus::MissingPhone => [
                'revenue_partner_id' => $row->revenue_partner_id,
                'partner_name' => $partner?->partner_name ?? $row->partner_name,
                'status' => RevenueImportRowStatus::MissingPhone,
                'error' => $note ?? 'Marked missing phone manually.',
            ],
            RevenueImportRowStatus::Invalid => [
                'revenue_partner_id' => $row->revenue_partner_id,
                'partner_name' => $row->partner_name,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => $note ?? 'Marked invalid manually.',
            ],
            RevenueImportRowStatus::Duplicate => [
                'revenue_partner_id' => $row->revenue_partner_id,
                'partner_name' => $row->partner_name,
                'status' => RevenueImportRowStatus::Duplicate,
                'error' => $note ?? 'Marked duplicate manually.',
            ],
            RevenueImportRowStatus::Sent => throw ValidationException::withMessages([
                'status' => 'Use Send SMS to mark a row as Sent.',
            ]),
        };

        $row->forceFill($payload)->save();
    }

    /**
     * @return array{
     *   revenue_partner_id: int,
     *   partner_name: ?string,
     *   service_id: ?string,
     *   short_code: ?string,
     *   status: RevenueImportRowStatus,
     *   error: null
     * }
     */
    protected function manualMatchedPayload(
        RevenueImportRow $row,
        ?RevenuePartner $partner,
        ?int $ownerUserId,
    ): array {
        if (! $partner) {
            $lookup = $this->partners->resolve($row->service_id, $row->short_code, $ownerUserId);
            $partner = $lookup['ok'] ? $lookup['partner'] : null;
        }

        if (! $partner) {
            throw ValidationException::withMessages([
                'status' => 'Cannot mark Ready — no master partner for this Service ID / Short code.',
            ]);
        }

        if (! $partner->is_active) {
            throw ValidationException::withMessages([
                'status' => 'Cannot mark Ready — partner is inactive.',
            ]);
        }

        if (! $partner->hasUsablePhone()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot mark Ready — partner has no usable phone. Sync phone or set it on the master list.',
            ]);
        }

        return [
            'revenue_partner_id' => $partner->id,
            'partner_name' => $partner->partner_name,
            'service_id' => $partner->service_id ?? $row->service_id,
            'short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $row->short_code,
            'status' => RevenueImportRowStatus::Matched,
            'error' => null,
        ];
    }

    protected function assertImportEditable(RevenueImport $import): void
    {
        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            throw ValidationException::withMessages(['import' => 'This import can no longer be edited.']);
        }
    }

    /**
     * @param  array{service_id?: mixed, short_code?: mixed, amount?: mixed}  $data
     */
    public function updateRow(RevenueImportRow $row, array $data, User $actor): void
    {
        $import = $row->import;
        if (! $import) {
            throw ValidationException::withMessages(['row' => 'Import not found.']);
        }

        $this->assertCanManage($actor, $import);
        $this->assertImportEditable($import);

        if ($row->wasSent() || $row->status === RevenueImportRowStatus::Sent) {
            throw ValidationException::withMessages(['row' => 'This row was already sent and cannot be edited.']);
        }

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            throw ValidationException::withMessages(['row' => 'This import can no longer be edited.']);
        }

        $serviceId = RevenuePartnerResolver::normalize($data['service_id'] ?? $row->service_id);
        $shortCode = RevenuePartnerResolver::normalize($data['short_code'] ?? $row->short_code);
        $amount = isset($data['amount']) && is_numeric($data['amount'])
            ? round((float) $data['amount'], 4)
            : ($row->amount !== null ? (float) $row->amount : null);

        if ($serviceId === null) {
            throw ValidationException::withMessages(['service_id' => 'Service ID is required.']);
        }
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Revenue must be a positive number.']);
        }

        $vasServiceId = (int) $import->vas_service_id;
        $ownerUserId = $this->ownerUserIdForImport($import);

        DB::transaction(function () use ($import, $row, $serviceId, $shortCode, $amount, $vasServiceId, $ownerUserId): void {
            $amUserId = $import->created_by_user_id ? (int) $import->created_by_user_id : null;
            $seen = [];
            foreach ($import->rows()->with('partner')->where('id', '!=', $row->id)->orderBy('id')->get() as $other) {
                $this->rememberSeenFingerprint($seen, $other, $import);
            }

            $payload = $this->classify(
                serviceId: $serviceId,
                shortCode: $shortCode,
                amount: $amount,
                seen: $seen,
                ownerUserId: $ownerUserId,
                period: (string) $import->period,
                excludeRowId: (int) $row->id,
                catalogServiceId: $vasServiceId,
                amUserId: $amUserId,
            );

            $row->forceFill([
                'service_id' => $payload['resolved_service_id'] ?? $serviceId,
                'short_code' => $payload['resolved_short_code'] ?? $shortCode,
                'amount' => $payload['amount'],
                'amount_raw' => (string) $amount,
                'revenue_partner_id' => $payload['revenue_partner_id'],
                'partner_name' => $payload['partner_name'],
                'vas_service_id' => $vasServiceId,
                'status' => $payload['status'],
                'error' => $payload['error'],
            ])->save();

            $import->resolveStatusFromRows();
        });
    }

    public function actorCanManage(User $actor, RevenueImport $import): bool
    {
        if ($actor->canAccessAllRevenue()) {
            return true;
        }

        return (int) $import->created_by_user_id === (int) $actor->id;
    }

    public function registerMissingPartners(RevenueImport $import): int
    {
        $created = 0;
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);

        $vasServiceId = (int) $import->vas_service_id;
        $matchOwnerId = $this->ownerUserIdForImport($import);
        $createOwnerId = $import->created_by_user_id
            ? (int) $import->created_by_user_id
            : ($actor?->id);

        DB::transaction(function () use ($import, &$created, $vasServiceId, $matchOwnerId, $createOwnerId): void {
            $rows = $import->rows()
                ->where('status', RevenueImportRowStatus::MissingPartner->value)
                ->where(function ($q): void {
                    $q->whereNotNull('service_id')->orWhereNotNull('short_code');
                })
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $lookup = $this->partners->resolveForUpsert($row->service_id, $row->short_code, $matchOwnerId);
                if (! $lookup['ok'] || ($lookup['service_id'] === null && $lookup['short_code'] === null)) {
                    $row->forceFill([
                        'status' => RevenueImportRowStatus::Invalid,
                        'error' => $lookup['error'] ?? 'Cannot register partner without service ID or short code.',
                    ])->save();

                    continue;
                }

                $partner = $lookup['partner'];
                if (! $partner) {
                    $attrs = [
                        'short_code' => $lookup['short_code'] ?? $row->short_code,
                        'partner_name' => filled($row->partner_name)
                            ? (string) $row->partner_name
                            : ('Partner '.($lookup['service_id'] ?? $lookup['short_code'])),
                        'vas_service_id' => $vasServiceId,
                        'created_by_user_id' => $createOwnerId,
                        'phone' => null,
                        'is_active' => true,
                    ];

                    if ($lookup['service_id'] !== null) {
                        $partner = RevenuePartner::query()->firstOrCreate(
                            ['service_id' => (string) $lookup['service_id']],
                            $attrs,
                        );
                    } else {
                        $partner = RevenuePartner::query()->firstOrCreate(
                            ['short_code' => (string) $lookup['short_code']],
                            array_merge($attrs, ['service_id' => null]),
                        );
                    }

                    if ($partner->wasRecentlyCreated) {
                        $created++;
                    } elseif ($createOwnerId && ! $partner->created_by_user_id) {
                        $partner->forceFill(['created_by_user_id' => $createOwnerId])->save();
                    }
                } else {
                    $updates = [];
                    if ($lookup['short_code'] && ! $partner->short_code) {
                        $updates['short_code'] = $lookup['short_code'];
                    }
                    if (! $partner->vas_service_id) {
                        $updates['vas_service_id'] = $vasServiceId;
                    }
                    if ($createOwnerId && ! $partner->created_by_user_id) {
                        $updates['created_by_user_id'] = $createOwnerId;
                    }
                    if ($updates !== []) {
                        $partner->forceFill($updates)->save();
                    }
                }

                $row->forceFill([
                    'revenue_partner_id' => $partner->id,
                    'partner_name' => $partner->partner_name,
                    'service_id' => $partner->service_id,
                    'short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $row->short_code,
                    'vas_service_id' => $partner->vas_service_id ?: $vasServiceId,
                    'status' => $partner->hasUsablePhone()
                        ? RevenueImportRowStatus::Matched
                        : RevenueImportRowStatus::MissingPhone,
                    'error' => $partner->hasUsablePhone()
                        ? null
                        : 'Set phone on the master list before SMS.',
                ])->save();
            }

            $import->resolveStatusFromRows();
        });

        return $created;
    }

    public function correctPeriod(
        RevenueImport $import,
        string $period,
        User $actor,
        bool $resetSentRowsForResend = false,
        ?string $messageTemplate = null,
    ): array {
        $this->assertCanManage($actor, $import);
        $this->assertImportEditable($import);

        $period = trim($period);
        if ($period === '') {
            throw ValidationException::withMessages(['period' => 'Month is required.']);
        }

        $template = $messageTemplate !== null ? trim($messageTemplate) : null;
        if ($template !== null) {
            if ($template === '') {
                throw ValidationException::withMessages(['message_template' => 'SMS template is required.']);
            }
            if (mb_strlen($template) > 640) {
                throw ValidationException::withMessages(['message_template' => 'SMS template max 640 characters.']);
            }
        }

        $resetRows = 0;

        DB::transaction(function () use ($import, $period, $resetSentRowsForResend, $template, &$resetRows): void {
            $import->loadMissing('vasService');
            $oldPeriod = (string) $import->period;
            $serviceName = $import->vasService?->name ?: 'Service';
            $autoTitle = $serviceName.' — '.$oldPeriod;
            $newTitle = $serviceName.' — '.$period;

            $title = (string) $import->title;
            if ($title === '' || $title === $autoTitle || str_ends_with($title, ' — '.$oldPeriod)) {
                $title = $newTitle;
            }

            $payload = [
                'period' => $period,
                'title' => $title,
            ];
            if ($template !== null) {
                $payload['message_template'] = $template;
            }

            $import->forceFill($payload)->save();

            if ($resetSentRowsForResend) {
                $rows = $import->rows()
                    ->with('partner')
                    ->where(function ($q): void {
                        $q->where('status', RevenueImportRowStatus::Sent->value)
                            ->orWhereNotNull('sent_at')
                            ->orWhereNotNull('bulk_message_id')
                            ->orWhereNotNull('bulk_message_recipient_id');
                    })
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    $partner = $row->partner;
                    $ready = $partner && $partner->hasUsablePhone();
                    $row->forceFill([
                        'status' => $ready
                            ? RevenueImportRowStatus::Matched
                            : RevenueImportRowStatus::MissingPhone,
                        'error' => $ready
                            ? null
                            : 'Partner phone missing after month correction — set phone then Rematch / Sync phones.',
                        'sent_at' => null,
                        'bulk_message_id' => null,
                        'bulk_message_recipient_id' => null,
                    ])->save();
                    $resetRows++;
                }

                $import->forceFill([
                    'bulk_message_id' => null,
                    'sent_at' => null,
                    'sent_by_user_id' => null,
                ])->save();
            }

            $import->resolveStatusFromRows();
        });

        return [
            'period' => $period,
            'title' => (string) $import->fresh()->title,
            'reset_rows' => $resetRows,
        ];
    }

    public function sendViaBulkMessage(RevenueImport $import, ?string $messageTemplate = null): BulkMessage
    {
        return $this->sendRowsViaBulkMessage($import, null, $messageTemplate);
    }

    /**
     * Queue SMS for ready (matched + phone) rows that have not been sent yet.
     *
     * @param  iterable<int>|null  $rowIds  null = all unsent ready rows on the import
     */
    public function sendRowsViaBulkMessage(
        RevenueImport $import,
        ?iterable $rowIds = null,
        ?string $messageTemplate = null,
    ): BulkMessage {
        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor || ! $this->actorCanSend($actor, $import)) {
            throw ValidationException::withMessages([
                'import' => 'You can only send SMS for imports you created.',
            ]);
        }

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'import' => $import->status === RevenueImportStatus::Completed
                    ? 'This import is already completed.'
                    : 'SMS is already sending for this import. Wait for it to finish.',
            ]);
        }

        $template = trim((string) ($messageTemplate ?? $import->message_template ?: self::DEFAULT_SMS_TEMPLATE));
        if ($template === '' || mb_strlen($template) > 640) {
            throw ValidationException::withMessages(['message' => 'SMS template is required (max 640).']);
        }

        $ids = $rowIds === null
            ? null
            : collect($rowIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        if ($ids !== null) {
            $selected = $import->rows()->whereIn('id', $ids)->with('partner')->orderBy('id')->get();
            if ($selected->isEmpty()) {
                throw ValidationException::withMessages(['import' => 'No rows selected.']);
            }

            $notReady = $selected->filter(
                fn (RevenueImportRow $row): bool => $row->status !== RevenueImportRowStatus::Matched
                    || $row->wasSent()
                    || ! $row->partner
                    || ! $row->partner->hasUsablePhone()
            );

            if ($notReady->isNotEmpty()) {
                $sample = $notReady->take(3)->map(function (RevenueImportRow $row): string {
                    $key = $row->service_id ?: $row->short_code ?: "#{$row->id}";
                    $status = $row->status instanceof RevenueImportRowStatus
                        ? $row->status->label()
                        : (string) $row->status;

                    return "{$key} ({$status})";
                })->implode(', ');

                throw ValidationException::withMessages([
                    'import' => "Only Ready rows can be sent. {$notReady->count()} selected row(s) are not Ready"
                        .($sample !== '' ? ": {$sample}" : '.'),
                ]);
            }
        }

        $query = $import->rows()
            ->where('status', RevenueImportRowStatus::Matched->value)
            ->whereNull('sent_at')
            ->whereNull('bulk_message_id')
            ->with(['partner', 'vasService'])
            ->orderBy('id');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $ready = $query->get();

        if ($ready->isEmpty()) {
            throw ValidationException::withMessages([
                'import' => $ids !== null
                    ? 'No selected Ready rows left to send.'
                    : 'No Ready rows left to send.',
            ]);
        }

        foreach ($ready as $row) {
            $partner = $row->partner;
            if (! $partner || ! $partner->hasUsablePhone()) {
                throw ValidationException::withMessages([
                    'import' => "Row {$row->service_id} has no usable partner phone.",
                ]);
            }
            if ($this->alreadySentForRow($row, $import, (int) $row->id)) {
                throw ValidationException::withMessages([
                    'import' => "SMS already sent for period {$import->period} and service ID {$row->service_id}.",
                ]);
            }
        }

        return DB::transaction(function () use ($import, $template, $ready, $actor): BulkMessage {
            $title = $import->title;
            if ($ready->count() < (int) $import->matched_count) {
                $title .= ' (partial '.$ready->count().')';
            }

            $campaign = BulkMessage::query()->create([
                'title' => $title,
                'message' => $template,
                'source_filename' => $import->source_filename,
                'source_path' => null,
                'status' => BulkMessageStatus::Draft,
                'created_by_user_id' => $actor->id,
            ]);

            $serviceLabel = $import->vasService?->name ?? 'Service';
            $sentAt = now();

            foreach ($ready as $index => $row) {
                $partner = $row->partner;
                $phone = $partner?->phone;
                $normalized = PhoneNumber::normalize($phone);
                $company = $this->findCompanyByLastNine($normalized);

                $recipient = BulkMessageRecipient::query()->create([
                    'campaign_id' => $campaign->id,
                    'company_id' => $company?->id,
                    'phone_raw' => $phone,
                    'phone_normalized' => $normalized,
                    'company_name' => $partner?->partner_name ?: $row->partner_name,
                    'company_tin' => $company?->tin,
                    'variables' => [
                        'period' => $import->period,
                        'service_type' => $serviceLabel,
                        'service_id' => (string) ($partner?->service_id ?: $row->service_id),
                        'amount' => $this->formatAmount((float) $row->amount),
                        'company_name' => (string) ($partner?->partner_name ?: $row->partner_name ?: 'Partner'),
                    ],
                    'row_number' => $row->row_number ?: ($index + 1),
                    'status' => BulkMessageRecipientStatus::Pending,
                    'error' => null,
                ]);

                $row->forceFill([
                    'bulk_message_id' => $campaign->id,
                    'bulk_message_recipient_id' => $recipient->id,
                    'sent_at' => $sentAt,
                    'status' => RevenueImportRowStatus::Sent,
                    'error' => null,
                ])->save();
            }

            $campaign->refreshCounts();
            $import->forceFill([
                'bulk_message_id' => $campaign->id,
                'message_template' => $template,
                'status' => RevenueImportStatus::Sending,
                'sent_at' => $import->sent_at ?? $sentAt,
                'sent_by_user_id' => $actor->id,
            ])->save();
            $import->refreshCounts();

            $this->bulkMessages->queue($campaign->fresh());

            return $campaign->fresh();
        });
    }

    public function syncSendStatus(RevenueImport $import): void
    {
        if ($import->status !== RevenueImportStatus::Sending || ! $import->bulk_message_id) {
            return;
        }

        $campaign = $import->bulkMessage;
        if (! $campaign) {
            return;
        }

        if ($campaign->status === BulkMessageStatus::Completed) {
            $this->finalizeAfterCampaign($import);
        } elseif ($campaign->status === BulkMessageStatus::Failed) {
            // Unlock so remaining ready rows can still be sent after fixing failures.
            $import->forceFill(['status' => RevenueImportStatus::Failed])->save();
            $import->refresh();
            if ($this->unsentReadyCount($import) > 0
                || $import->missing_partner_count > 0
                || $import->missing_phone_count > 0) {
                $import->forceFill(['status' => RevenueImportStatus::Reviewing])->save();
                $import->resolveStatusFromRows();
            }
        }
    }

    protected function finalizeAfterCampaign(RevenueImport $import): void
    {
        $import->refreshCounts();
        $unsentReady = $this->unsentReadyCount($import);

        if ($unsentReady === 0
            && $import->missing_partner_count === 0
            && $import->missing_phone_count === 0) {
            $import->forceFill(['status' => RevenueImportStatus::Completed])->save();

            return;
        }

        // Unlock for more partial sends / fixes.
        $import->forceFill(['status' => RevenueImportStatus::Reviewing])->save();
        $import->resolveStatusFromRows();
    }

    public function unsentReadyCount(RevenueImport $import): int
    {
        return $import->rows()
            ->where('status', RevenueImportRowStatus::Matched->value)
            ->whereNull('sent_at')
            ->whereNull('bulk_message_id')
            ->count();
    }

    public function actorCanSend(User $actor, RevenueImport $import): bool
    {
        return $this->actorCanManage($actor, $import);
    }

    public function rowCanSendSms(RevenueImportRow $row, ?RevenueImport $import = null): bool
    {
        $import ??= $row->import;
        if (! $import) {
            return false;
        }

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            return false;
        }

        if ($row->wasSent() || $row->status === RevenueImportRowStatus::Sent) {
            return false;
        }

        if ($row->status !== RevenueImportRowStatus::Matched) {
            return false;
        }

        $partner = $row->partner;

        return $partner !== null && $partner->hasUsablePhone();
    }

    public function importCanSendSms(RevenueImport $import): bool
    {
        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            return false;
        }

        return $this->unsentReadyCount($import) > 0;
    }

    public function rowCanRetrySms(RevenueImportRow $row): bool
    {
        if (! $row->wasSent()) {
            return false;
        }

        $recipient = $row->smsRecipient;
        if (! $recipient) {
            return false;
        }

        return in_array($recipient->status, [
            BulkMessageRecipientStatus::Failed,
            BulkMessageRecipientStatus::Skipped,
        ], true);
    }

    /**
     * Re-queue failed/skipped SMS for one or more already-sent revenue rows.
     *
     * @param  iterable<int>  $rowIds
     * @return array{retried: int, skipped: int, errors: list<string>}
     */
    public function retryFailedSms(RevenueImport $import, iterable $rowIds): array
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor || ! $this->actorCanSend($actor, $import)) {
            throw ValidationException::withMessages([
                'import' => 'You can only retry SMS for imports you manage.',
            ]);
        }

        $ids = collect($rowIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $retried = 0;
        $skipped = 0;
        $errors = [];

        $rows = $import->rows()
            ->whereIn('id', $ids)
            ->with('smsRecipient')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            try {
                $this->retryFailedSmsForRow($row);
                $retried++;
            } catch (ValidationException $e) {
                $skipped++;
                $errors[] = ($row->service_id ?: $row->short_code ?: "#{$row->id}").': '
                    .(collect($e->errors())->flatten()->first() ?? $e->getMessage());
            }
        }

        return [
            'retried' => $retried,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function retryFailedSmsForRow(RevenueImportRow $row): void
    {
        if (! $this->rowCanRetrySms($row)) {
            throw ValidationException::withMessages([
                'row' => 'Only Failed or Skipped SMS rows can be retried.',
            ]);
        }

        $recipient = $row->smsRecipient;
        if (! $recipient) {
            throw ValidationException::withMessages(['row' => 'No SMS recipient linked to this row.']);
        }

        $recipient->forceFill([
            'status' => BulkMessageRecipientStatus::Pending,
            'error' => null,
        ])->save();

        $campaign = $recipient->bulkMessage;
        if ($campaign && in_array($campaign->status, [
            BulkMessageStatus::Draft,
            BulkMessageStatus::Completed,
            BulkMessageStatus::Failed,
        ], true)) {
            $campaign->forceFill([
                'status' => BulkMessageStatus::Queued,
                'queued_at' => now(),
                'completed_at' => null,
            ])->save();
        }

        SendBulkMessageRecipientJob::dispatch((int) $recipient->id);
    }

    /**
     * Duplicate policy from App settings (scope + match fields + enforcement).
     */
    public function duplicatePolicy(): RevenueDuplicatePolicy
    {
        return AppSetting::revenueDuplicatePolicy();
    }

    /**
     * @deprecated Use duplicatePolicy()->enforces()
     */
    public function shouldBlockDuplicates(): bool
    {
        return $this->duplicatePolicy()->enforces();
    }

    /**
     * True when prior SMS already matches the configured policy for this partner.
     *
     * @param  array{
     *   amount?: ?float,
     *   am_user_id?: ?int,
     *   catalog_service_id?: ?int
     * }  $extra
     */
    public function wouldDoubleSend(
        RevenuePartner $partner,
        string $period,
        ?int $excludeRowId = null,
        array $extra = [],
    ): bool {
        $policy = $this->duplicatePolicy();
        if (! $policy->checksPriorSends()) {
            return false;
        }

        return $this->alreadySentMatchingPolicy(
            $policy,
            [
                'service_id' => RevenuePartnerResolver::normalize($partner->service_id),
                'short_code' => RevenuePartnerResolver::normalize($partner->short_code),
                'month' => trim($period),
                'am' => (string) ($extra['am_user_id'] ?? ''),
                'catalog_service' => (string) ($extra['catalog_service_id'] ?? ''),
                'company' => (string) ($partner->company_id ?? ''),
                'partner' => (string) $partner->id,
                'amount' => isset($extra['amount']) && is_numeric($extra['amount'])
                    ? number_format((float) $extra['amount'], 4, '.', '')
                    : '',
            ],
            $excludeRowId,
        );
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{
     *   revenue_partner_id:?int,
     *   status: RevenueImportRowStatus,
     *   error:?string,
     *   amount:?float,
     *   partner_name:?string,
     *   resolved_service_id:?string,
     *   resolved_short_code:?string
     * }
     */
    protected function classify(
        ?string $serviceId,
        ?string $shortCode,
        ?float $amount,
        array &$seen,
        ?int $ownerUserId = null,
        ?string $period = null,
        ?int $excludeRowId = null,
        ?int $catalogServiceId = null,
        ?int $amUserId = null,
    ): array {
        $serviceId = RevenuePartnerResolver::normalize($serviceId);
        $shortCode = RevenuePartnerResolver::normalize($shortCode);
        $policy = $this->duplicatePolicy();

        if ($serviceId === null && $shortCode === null) {
            return $this->invalid('Service ID and short code are both empty.', $amount);
        }
        if ($amount === null || $amount <= 0) {
            return $this->invalid('Revenue must be a positive number.', $amount, $serviceId, $shortCode);
        }

        $lookup = $this->partners->resolve($serviceId, $shortCode, $ownerUserId);
        if (! $lookup['ok']) {
            return $this->invalid((string) $lookup['error'], $amount, $lookup['service_id'], $lookup['short_code']);
        }

        $partner = $lookup['partner'];
        $serviceId = $lookup['service_id'];
        $shortCode = $lookup['short_code'];

        $context = $this->duplicateContext(
            serviceId: $serviceId,
            shortCode: $shortCode,
            amount: $amount,
            period: $period,
            partner: $partner,
            amUserId: $amUserId ?? $ownerUserId,
            catalogServiceId: $catalogServiceId,
        );

        if ($policy->checksWithinImport()) {
            $fingerprint = $this->duplicateFingerprint($policy, $context);
            if ($fingerprint !== '' && isset($seen[$fingerprint])) {
                return [
                    'revenue_partner_id' => $partner?->id,
                    'status' => RevenueImportRowStatus::Duplicate,
                    'error' => 'Duplicate in this import ('.$policy->matchRuleLabel().').',
                    'amount' => $amount,
                    'partner_name' => $partner?->partner_name,
                    'resolved_service_id' => $serviceId,
                    'resolved_short_code' => $shortCode,
                ];
            }
            if ($fingerprint !== '') {
                $seen[$fingerprint] = true;
            }
        }

        if (! $partner) {
            return [
                'revenue_partner_id' => null,
                'status' => RevenueImportRowStatus::MissingPartner,
                'error' => 'Unresolved: service ID / short code not in your partner master list.',
                'amount' => $amount,
                'partner_name' => null,
                'resolved_service_id' => $serviceId,
                'resolved_short_code' => $shortCode,
            ];
        }

        if (! $partner->is_active) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => 'Inactive on master list.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'resolved_service_id' => $partner->service_id,
                'resolved_short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
            ];
        }

        if (! $partner->hasUsablePhone()) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::MissingPhone,
                'error' => 'Phone missing on master list.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'resolved_service_id' => $partner->service_id,
                'resolved_short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
            ];
        }

        $context = $this->duplicateContext(
            serviceId: $partner->service_id,
            shortCode: RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
            amount: $amount,
            period: $period,
            partner: $partner,
            amUserId: $amUserId ?? $ownerUserId,
            catalogServiceId: $catalogServiceId,
        );

        if ($policy->checksPriorSends()
            && $this->alreadySentMatchingPolicy($policy, $context, $excludeRowId)) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Duplicate,
                'error' => 'SMS already sent matching '.$policy->matchRuleLabel().'.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'resolved_service_id' => $partner->service_id,
                'resolved_short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
            ];
        }

        return [
            'revenue_partner_id' => $partner->id,
            'status' => RevenueImportRowStatus::Matched,
            'error' => null,
            'amount' => $amount,
            'partner_name' => $partner->partner_name,
            'resolved_service_id' => $partner->service_id,
            'resolved_short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
        ];
    }

    protected function alreadySentForRow(
        RevenueImportRow $row,
        RevenueImport $import,
        ?int $excludeRowId = null,
    ): bool {
        $policy = $this->duplicatePolicy();
        if (! $policy->checksPriorSends()) {
            return false;
        }

        $row->loadMissing('partner');

        return $this->alreadySentMatchingPolicy(
            $policy,
            $this->duplicateContextFromRow($row, $import),
            $excludeRowId,
        );
    }

    /**
     * @return array{
     *   service_id: ?string,
     *   short_code: ?string,
     *   month: string,
     *   am: string,
     *   catalog_service: string,
     *   company: string,
     *   partner: string,
     *   amount: string
     * }
     */
    protected function duplicateContextFromRow(RevenueImportRow $row, RevenueImport $import): array
    {
        return $this->duplicateContext(
            serviceId: $row->service_id,
            shortCode: $row->short_code,
            amount: $row->amount !== null ? (float) $row->amount : null,
            period: (string) $import->period,
            partner: $row->partner,
            amUserId: $import->created_by_user_id ? (int) $import->created_by_user_id : null,
            catalogServiceId: (int) ($row->vas_service_id ?: $import->vas_service_id),
        );
    }

    /**
     * @return array{
     *   service_id: ?string,
     *   short_code: ?string,
     *   month: string,
     *   am: string,
     *   catalog_service: string,
     *   company: string,
     *   partner: string,
     *   amount: string
     * }
     */
    protected function duplicateContext(
        ?string $serviceId,
        ?string $shortCode,
        ?float $amount,
        ?string $period,
        ?RevenuePartner $partner,
        ?int $amUserId,
        ?int $catalogServiceId,
    ): array {
        return [
            'service_id' => RevenuePartnerResolver::normalize($serviceId),
            'short_code' => RevenuePartnerResolver::normalize($shortCode),
            'month' => trim((string) $period),
            'am' => $amUserId ? (string) $amUserId : '',
            'catalog_service' => $catalogServiceId ? (string) $catalogServiceId : '',
            'company' => $partner?->company_id ? (string) $partner->company_id : '',
            'partner' => $partner?->id ? (string) $partner->id : '',
            'amount' => $amount !== null ? number_format($amount, 4, '.', '') : '',
        ];
    }

    /**
     * @param  array<string, true>  $seen
     */
    protected function rememberSeenFingerprint(array &$seen, RevenueImportRow $row, RevenueImport $import): void
    {
        $policy = $this->duplicatePolicy();
        if (! $policy->checksWithinImport()) {
            return;
        }

        $fingerprint = $this->duplicateFingerprint($policy, $this->duplicateContextFromRow($row, $import));
        if ($fingerprint !== '') {
            $seen[$fingerprint] = true;
        }
    }

    /**
     * AND-concatenation of selected match fields, e.g. "service_id=… AND month=…".
     *
     * @param  array<string, string|null>  $context
     */
    protected function duplicateFingerprint(RevenueDuplicatePolicy $policy, array $context): string
    {
        if ($policy->match === []) {
            return '';
        }

        return collect($policy->match)
            ->map(fn (string $field): string => $field.'='.($context[$field] ?? ''))
            ->implode(' AND ');
    }

    /**
     * Prior SMS rows that match every selected policy field (AND).
     *
     * @param  array<string, string|null>  $context
     */
    protected function alreadySentMatchingPolicy(
        RevenueDuplicatePolicy $policy,
        array $context,
        ?int $excludeRowId = null,
    ): bool {
        if ($policy->match === []) {
            return false;
        }

        $hasIdentity = false;
        foreach ($policy->match as $field) {
            $value = trim((string) ($context[$field] ?? ''));
            if ($value !== '') {
                $hasIdentity = true;
                break;
            }
        }
        if (! $hasIdentity) {
            return false;
        }

        $query = RevenueImportRow::query()
            ->where(function ($q): void {
                $q->where('status', RevenueImportRowStatus::Sent->value)
                    ->orWhereNotNull('sent_at')
                    ->orWhereNotNull('bulk_message_id');
            })
            ->when($excludeRowId, fn ($q) => $q->where('id', '!=', $excludeRowId));

        foreach ($policy->match as $field) {
            $value = trim((string) ($context[$field] ?? ''));
            if ($value === '') {
                return false;
            }

            match ($field) {
                RevenueDuplicatePolicy::MATCH_SERVICE_ID => $query->where('service_id', $value),
                RevenueDuplicatePolicy::MATCH_SHORT_CODE => $query->where('short_code', $value),
                RevenueDuplicatePolicy::MATCH_MONTH => $query->whereHas(
                    'import',
                    fn ($q) => $q->where('period', $value)
                ),
                RevenueDuplicatePolicy::MATCH_AM => $query->whereHas(
                    'import',
                    fn ($q) => $q->where('created_by_user_id', (int) $value)
                ),
                RevenueDuplicatePolicy::MATCH_CATALOG => $query->where('vas_service_id', (int) $value),
                RevenueDuplicatePolicy::MATCH_COMPANY => $query->whereHas(
                    'partner',
                    fn ($q) => $q->where('company_id', (int) $value)
                ),
                RevenueDuplicatePolicy::MATCH_PARTNER => $query->where('revenue_partner_id', (int) $value),
                RevenueDuplicatePolicy::MATCH_AMOUNT => $query->where('amount', $value),
                default => null,
            };
        }

        return $query->exists();
    }

    /**
     * @return array{
     *   revenue_partner_id: null,
     *   status: RevenueImportRowStatus,
     *   error: string,
     *   amount: ?float,
     *   partner_name: null,
     *   resolved_service_id: ?string,
     *   resolved_short_code: ?string
     * }
     */
    protected function invalid(
        string $error,
        ?float $amount,
        ?string $serviceId = null,
        ?string $shortCode = null,
    ): array {
        return [
            'revenue_partner_id' => null,
            'status' => RevenueImportRowStatus::Invalid,
            'error' => $error,
            'amount' => $amount,
            'partner_name' => null,
            'resolved_service_id' => $serviceId,
            'resolved_short_code' => $shortCode,
        ];
    }

    protected function assertCanManage(?User $actor, RevenueImport $import): void
    {
        if (! $actor) {
            throw ValidationException::withMessages(['import' => 'Not authenticated.']);
        }
        if ($this->actorCanManage($actor, $import)) {
            return;
        }

        throw ValidationException::withMessages(['import' => 'You can only manage imports you created.']);
    }

    /**
     * Scope partner matching to the AM who owns the import.
     * Admins / super admins match the full master list.
     */
    protected function ownerUserIdForImport(RevenueImport $import): ?int
    {
        $userId = $import->created_by_user_id ? (int) $import->created_by_user_id : null;
        if (! $userId) {
            return null;
        }

        $user = User::query()->find($userId);
        if ($user?->canAccessAllRevenue()) {
            return null;
        }

        return $userId;
    }

    protected function formatAmount(float $amount): string
    {
        return floor($amount) == $amount
            ? number_format($amount, 0, '.', ',')
            : number_format($amount, 2, '.', ',');
    }

    protected function findCompanyByLastNine(string $lastNine): ?Company
    {
        if (strlen($lastNine) !== 9) {
            return null;
        }

        return Company::query()
            ->whereRaw(
                "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                [$lastNine]
            )
            ->orderBy('id')
            ->first();
    }
}
