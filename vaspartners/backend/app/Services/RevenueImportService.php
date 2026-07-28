<?php

namespace App\Services;

use App\Enums\BulkMessageRecipientStatus;
use App\Enums\BulkMessageStatus;
use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Post-import actions for monthly revenue (match / register / send).
 * CSV ingest uses Filament ImportAction + MonthlyRevenueImporter.
 */
class RevenueImportService
{
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
            $seen = [];
            foreach ($import->rows()->orderBy('id')->get() as $row) {
                $payload = $this->classify(
                    serviceId: $row->service_id,
                    shortCode: $row->short_code,
                    amount: $row->amount !== null ? (float) $row->amount : null,
                    seen: $seen,
                    ownerUserId: $ownerUserId,
                    period: $period,
                    excludeImportId: (int) $import->id,
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
     * @param  array{service_id?: mixed, short_code?: mixed, amount?: mixed}  $data
     */
    public function updateRow(RevenueImportRow $row, array $data, User $actor): void
    {
        $import = $row->import;
        if (! $import) {
            throw ValidationException::withMessages(['row' => 'Import not found.']);
        }

        $this->assertCanManage($actor, $import);

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
            $seen = [];
            foreach ($import->rows()->where('id', '!=', $row->id)->orderBy('id')->get() as $other) {
                $key = (RevenuePartnerResolver::normalize($other->service_id) ?? '').'|'
                    .(RevenuePartnerResolver::normalize($other->short_code) ?? '');
                $seen[$key] = true;
            }

            $payload = $this->classify(
                serviceId: $serviceId,
                shortCode: $shortCode,
                amount: $amount,
                seen: $seen,
                ownerUserId: $ownerUserId,
                period: (string) $import->period,
                excludeImportId: (int) $import->id,
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
                if (! $lookup['ok'] || $lookup['service_id'] === null) {
                    $row->forceFill([
                        'status' => RevenueImportRowStatus::Invalid,
                        'error' => $lookup['error'] ?? 'Cannot register partner without service ID.',
                    ])->save();

                    continue;
                }

                $partner = $lookup['partner'];
                if (! $partner) {
                    $partner = RevenuePartner::query()->firstOrCreate(
                        ['service_id' => (string) $lookup['service_id']],
                        [
                            'short_code' => $lookup['short_code'] ?? $row->short_code,
                            'partner_name' => filled($row->partner_name) ? (string) $row->partner_name : ('Partner '.$lookup['service_id']),
                            'vas_service_id' => $vasServiceId,
                            'created_by_user_id' => $createOwnerId,
                            'phone' => null,
                            'is_active' => true,
                        ],
                    );

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

    public function sendViaBulkMessage(RevenueImport $import, ?string $messageTemplate = null): BulkMessage
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor || ! $this->actorCanSend($actor, $import)) {
            throw ValidationException::withMessages([
                'import' => 'You can only send SMS for imports you created.',
            ]);
        }

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)
            || filled($import->bulk_message_id)) {
            throw ValidationException::withMessages(['import' => 'This import was already sent. Double sending is blocked.']);
        }

        if ($import->missing_partner_count > 0 || $import->missing_phone_count > 0 || $import->invalid_count > 0) {
            throw ValidationException::withMessages(['import' => 'Fix unresolved / invalid / missing-phone rows before sending.']);
        }

        $template = trim((string) ($messageTemplate ?? $import->message_template ?: BulkMessageService::DEFAULT_MESSAGE));
        if ($template === '' || mb_strlen($template) > 640) {
            throw ValidationException::withMessages(['message' => 'SMS template is required (max 640).']);
        }

        $ready = $import->rows()
            ->where('status', RevenueImportRowStatus::Matched->value)
            ->with(['partner', 'vasService'])
            ->orderBy('id')
            ->get();

        if ($ready->isEmpty()) {
            throw ValidationException::withMessages(['import' => 'No ready rows to send.']);
        }

        // Block double send for the same partner + period already completed elsewhere.
        foreach ($ready as $row) {
            if ($this->alreadySentForPeriod($row, (string) $import->period, (int) $import->id)) {
                throw ValidationException::withMessages([
                    'import' => "SMS already sent for period {$import->period} and service ID {$row->service_id}. Rematch or remove that row.",
                ]);
            }
        }

        return DB::transaction(function () use ($import, $template, $ready, $actor): BulkMessage {
            $campaign = BulkMessage::query()->create([
                'title' => $import->title,
                'message' => $template,
                'source_filename' => $import->source_filename,
                'source_path' => null,
                'status' => BulkMessageStatus::Draft,
                'created_by_user_id' => $actor->id,
            ]);

            $serviceLabel = $import->vasService?->name ?? 'Service';

            foreach ($ready as $index => $row) {
                $partner = $row->partner;
                $phone = $partner?->phone;
                $normalized = PhoneNumber::normalize($phone);
                $company = $this->findCompanyByLastNine($normalized);

                BulkMessageRecipient::query()->create([
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
            }

            $campaign->refreshCounts();
            $import->forceFill([
                'bulk_message_id' => $campaign->id,
                'message_template' => $template,
                'status' => RevenueImportStatus::Sending,
                'sent_at' => now(),
                'sent_by_user_id' => $actor->id,
            ])->save();

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
            $import->forceFill(['status' => RevenueImportStatus::Completed])->save();
        } elseif ($campaign->status === BulkMessageStatus::Failed) {
            $import->forceFill(['status' => RevenueImportStatus::Failed])->save();
        }
    }

    public function actorCanSend(User $actor, RevenueImport $import): bool
    {
        return $this->actorCanManage($actor, $import);
    }

    /**
     * True when SMS was already queued/sent for this partner in the given month.
     */
    public function wouldDoubleSend(RevenuePartner $partner, string $period, ?int $excludeImportId = null): bool
    {
        return $this->alreadySentForPartnerPeriod(
            (int) $partner->id,
            $partner->service_id,
            $period,
            $excludeImportId,
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
        ?int $excludeImportId = null,
    ): array {
        $serviceId = RevenuePartnerResolver::normalize($serviceId);
        $shortCode = RevenuePartnerResolver::normalize($shortCode);

        if ($serviceId === null && $shortCode === null) {
            return $this->invalid('Service ID and short code are both empty.', $amount);
        }
        if ($amount === null || $amount <= 0) {
            return $this->invalid('Revenue must be a positive number.', $amount, $serviceId, $shortCode);
        }

        $dedupe = ($serviceId ?? '').'|'.($shortCode ?? '');
        if (isset($seen[$dedupe])) {
            return [
                'revenue_partner_id' => null,
                'status' => RevenueImportRowStatus::Duplicate,
                'error' => 'Duplicate in this import (same service_id / short_code).',
                'amount' => $amount,
                'partner_name' => null,
                'resolved_service_id' => $serviceId,
                'resolved_short_code' => $shortCode,
            ];
        }
        $seen[$dedupe] = true;

        $lookup = $this->partners->resolve($serviceId, $shortCode, $ownerUserId);
        if (! $lookup['ok']) {
            return $this->invalid((string) $lookup['error'], $amount, $lookup['service_id'], $lookup['short_code']);
        }

        $partner = $lookup['partner'];
        $serviceId = $lookup['service_id'];
        $shortCode = $lookup['short_code'];

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

        if ($period && $this->alreadySentForPartnerPeriod($partner->id, $partner->service_id, $period, $excludeImportId)) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Duplicate,
                'error' => "SMS already sent for this partner in period {$period}.",
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

    protected function alreadySentForPeriod(RevenueImportRow $row, string $period, int $excludeImportId): bool
    {
        return $this->alreadySentForPartnerPeriod(
            $row->revenue_partner_id ? (int) $row->revenue_partner_id : null,
            $row->service_id,
            $period,
            $excludeImportId,
        );
    }

    protected function alreadySentForPartnerPeriod(
        ?int $partnerId,
        ?string $serviceId,
        string $period,
        ?int $excludeImportId,
    ): bool {
        $period = trim($period);
        if ($period === '' || (! $partnerId && ! filled($serviceId))) {
            return false;
        }

        return RevenueImportRow::query()
            ->whereHas('import', function ($q) use ($period, $excludeImportId): void {
                $q->where('period', $period)
                    ->whereIn('status', [
                        RevenueImportStatus::Sending->value,
                        RevenueImportStatus::Completed->value,
                    ])
                    ->whereNotNull('bulk_message_id');
                if ($excludeImportId) {
                    $q->where('id', '!=', $excludeImportId);
                }
            })
            ->where(function ($q) use ($partnerId, $serviceId): void {
                if ($partnerId) {
                    $q->orWhere('revenue_partner_id', $partnerId);
                }
                if (filled($serviceId)) {
                    $q->orWhere('service_id', $serviceId);
                }
            })
            ->exists();
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
