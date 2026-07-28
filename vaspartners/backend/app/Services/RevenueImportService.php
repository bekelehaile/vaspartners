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
        $vasServiceId = (int) $import->vas_service_id;

        DB::transaction(function () use ($import, $vasServiceId): void {
            $seen = [];
            foreach ($import->rows()->orderBy('id')->get() as $row) {
                $payload = $this->classify(
                    serviceId: $row->service_id,
                    shortCode: $row->short_code,
                    amount: $row->amount !== null ? (float) $row->amount : null,
                    seen: $seen,
                    vasServiceId: $vasServiceId,
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
            throw ValidationException::withMessages(['service_id' => 'Billing service ID is required.']);
        }
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Revenue must be a positive number.']);
        }

        $vasServiceId = (int) $import->vas_service_id;

        DB::transaction(function () use ($import, $row, $serviceId, $shortCode, $amount, $vasServiceId): void {
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
                vasServiceId: $vasServiceId,
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

        if ((int) $import->created_by_user_id !== (int) $actor->id) {
            return false;
        }

        return $actor->managesRevenueService((int) $import->vas_service_id);
    }

    public function registerMissingPartners(RevenueImport $import): int
    {
        $created = 0;
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);

        $vasServiceId = (int) $import->vas_service_id;

        DB::transaction(function () use ($import, &$created, $vasServiceId): void {
            $rows = $import->rows()
                ->where('status', RevenueImportRowStatus::MissingPartner->value)
                ->where(function ($q): void {
                    $q->whereNotNull('service_id')->orWhereNotNull('short_code');
                })
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $lookup = $this->partners->resolveForUpsert($row->service_id, $row->short_code);
                if (! $lookup['ok'] || $lookup['service_id'] === null) {
                    $row->forceFill([
                        'status' => RevenueImportRowStatus::Invalid,
                        'error' => $lookup['error'] ?? 'Cannot register partner without billing service_id.',
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
                            'phone' => null,
                            'is_active' => true,
                        ],
                    );

                    if ($partner->wasRecentlyCreated) {
                        $created++;
                    }
                } else {
                    $updates = [];
                    if ($lookup['short_code'] && ! $partner->short_code) {
                        $updates['short_code'] = $lookup['short_code'];
                    }
                    if (! $partner->vas_service_id) {
                        $updates['vas_service_id'] = $vasServiceId;
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
                'import' => 'You can only send SMS for your own imports on an assigned catalog service.',
            ]);
        }

        if (in_array($import->status, [RevenueImportStatus::Sending], true)) {
            throw ValidationException::withMessages(['import' => 'This import is already sending.']);
        }

        if ($import->missing_partner_count > 0 || $import->missing_phone_count > 0) {
            throw ValidationException::withMessages(['import' => 'Fix unresolved / missing-phone rows before sending.']);
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
        ?int $vasServiceId,
    ): array {
        $serviceId = RevenuePartnerResolver::normalize($serviceId);
        $shortCode = RevenuePartnerResolver::normalize($shortCode);

        if ($serviceId === null && $shortCode === null) {
            return $this->invalid('Billing service ID and short code are both empty.', $amount);
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

        $lookup = $this->partners->resolve($serviceId, $shortCode);
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
                'error' => 'Unresolved: not in master list. Edit this row or add the partner, then Rematch.',
                'amount' => $amount,
                'partner_name' => null,
                'resolved_service_id' => $serviceId,
                'resolved_short_code' => $shortCode,
            ];
        }

        if ($vasServiceId && (int) $partner->vas_service_id !== $vasServiceId) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => 'Master partner is mapped to a different catalog service.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'resolved_service_id' => $partner->service_id,
                'resolved_short_code' => RevenuePartnerResolver::normalize($partner->short_code) ?? $shortCode,
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
