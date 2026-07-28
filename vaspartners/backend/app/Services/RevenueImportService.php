<?php

namespace App\Services;

use App\Enums\BulkMessageRecipientStatus;
use App\Enums\BulkMessageStatus;
use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Enums\RevenueServiceFamily;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Models\RevenueImport;
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
    ) {}

    public function rematch(RevenueImport $import): void
    {
        $family = $import->service_family instanceof RevenueServiceFamily
            ? $import->service_family
            : RevenueServiceFamily::tryFrom((string) $import->service_family);

        DB::transaction(function () use ($import, $family): void {
            $seen = [];
            foreach ($import->rows()->orderBy('id')->get() as $row) {
                $payload = $this->classify(
                    serviceId: $row->service_id,
                    shortCode: $row->short_code,
                    amount: $row->amount !== null ? (float) $row->amount : null,
                    seen: $seen,
                    family: $family,
                );
                $row->forceFill([
                    'revenue_partner_id' => $payload['revenue_partner_id'],
                    'partner_name' => $payload['partner_name'],
                    'service_type' => $payload['service_type'],
                    'service_id' => $payload['resolved_service_id'] ?? $row->service_id,
                    'status' => $payload['status'],
                    'error' => $payload['error'],
                    'amount' => $payload['amount'],
                ])->save();
            }
            $import->resolveStatusFromRows();
        });
    }

    public function registerMissingPartners(RevenueImport $import): int
    {
        $created = 0;
        /** @var User|null $actor */
        $actor = auth()->user();
        $this->assertCanManage($actor, $import);

        $family = $import->service_family instanceof RevenueServiceFamily
            ? $import->service_family
            : RevenueServiceFamily::tryFrom((string) $import->service_family);

        DB::transaction(function () use ($import, &$created, $family): void {
            $rows = $import->rows()
                ->where('status', RevenueImportRowStatus::MissingPartner->value)
                ->whereNotNull('service_id')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $partner = RevenuePartner::query()->firstOrCreate(
                    ['service_id' => (string) $row->service_id],
                    [
                        'short_code' => $row->short_code,
                        'partner_name' => filled($row->partner_name) ? (string) $row->partner_name : ('Partner '.$row->service_id),
                        'service_type' => $row->service_type,
                        'service_family' => $family?->value,
                        'phone' => null,
                        'is_active' => true,
                    ],
                );

                if ($partner->wasRecentlyCreated) {
                    $created++;
                }

                $row->forceFill([
                    'revenue_partner_id' => $partner->id,
                    'partner_name' => $partner->partner_name,
                    'service_type' => $partner->service_type,
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
                'import' => 'You can only send SMS for your own imports in an assigned service family.',
            ]);
        }

        if (in_array($import->status, [RevenueImportStatus::Sending], true)) {
            throw ValidationException::withMessages(['import' => 'This import is already sending.']);
        }

        if ($import->missing_partner_count > 0 || $import->missing_phone_count > 0) {
            throw ValidationException::withMessages(['import' => 'Fix missing partners / phones before sending.']);
        }

        $template = trim((string) ($messageTemplate ?? $import->message_template ?: BulkMessageService::DEFAULT_MESSAGE));
        if ($template === '' || mb_strlen($template) > 640) {
            throw ValidationException::withMessages(['message' => 'SMS template is required (max 640).']);
        }

        $ready = $import->rows()
            ->where('status', RevenueImportRowStatus::Matched->value)
            ->with('partner')
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

            $familyLabel = $import->service_family instanceof RevenueServiceFamily
                ? $import->service_family->label()
                : (string) $import->service_family;

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
                        'service_type' => (string) ($partner?->service_type ?: $familyLabel),
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
        if ($actor->canAccessAllRevenue()) {
            return true;
        }

        if ((int) $import->created_by_user_id !== (int) $actor->id) {
            return false;
        }

        $family = $import->service_family instanceof RevenueServiceFamily
            ? $import->service_family->value
            : (string) $import->service_family;

        return $actor->managesRevenueFamily($family);
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{revenue_partner_id:?int, status: RevenueImportRowStatus, error:?string, amount:?float, partner_name:?string, service_type:?string, resolved_service_id:?string}
     */
    protected function classify(
        ?string $serviceId,
        ?string $shortCode,
        ?float $amount,
        array &$seen,
        ?RevenueServiceFamily $family,
    ): array {
        $serviceId = filled($serviceId) ? trim($serviceId) : null;
        $shortCode = filled($shortCode) ? trim($shortCode) : null;

        if (! $serviceId && ! $shortCode) {
            return $this->invalid('Service ID and short code are both empty.', $amount);
        }
        if ($amount === null || $amount <= 0) {
            return $this->invalid('Revenue must be a positive number.', $amount);
        }

        $dedupe = ($serviceId ?? '').'|'.($shortCode ?? '');
        if (isset($seen[$dedupe])) {
            return [
                'revenue_partner_id' => null,
                'status' => RevenueImportRowStatus::Duplicate,
                'error' => 'Duplicate in this import.',
                'amount' => $amount,
                'partner_name' => null,
                'service_type' => null,
                'resolved_service_id' => $serviceId,
            ];
        }
        $seen[$dedupe] = true;

        $partner = $serviceId
            ? RevenuePartner::query()->where('service_id', $serviceId)->first()
            : null;
        if (! $partner && $shortCode) {
            $partner = RevenuePartner::query()->where('short_code', $shortCode)->first();
        }

        if (! $partner) {
            return [
                'revenue_partner_id' => null,
                'status' => RevenueImportRowStatus::MissingPartner,
                'error' => 'Not in master list.',
                'amount' => $amount,
                'partner_name' => null,
                'service_type' => null,
                'resolved_service_id' => $serviceId ?? $shortCode,
            ];
        }

        if ($family && $partner->service_family && $partner->service_family !== $family) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => 'Master family mismatch.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'service_type' => $partner->service_type,
                'resolved_service_id' => $partner->service_id,
            ];
        }

        if (! $partner->is_active) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::Invalid,
                'error' => 'Inactive on master list.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'service_type' => $partner->service_type,
                'resolved_service_id' => $partner->service_id,
            ];
        }

        if (! $partner->hasUsablePhone()) {
            return [
                'revenue_partner_id' => $partner->id,
                'status' => RevenueImportRowStatus::MissingPhone,
                'error' => 'Phone missing on master list.',
                'amount' => $amount,
                'partner_name' => $partner->partner_name,
                'service_type' => $partner->service_type,
                'resolved_service_id' => $partner->service_id,
            ];
        }

        return [
            'revenue_partner_id' => $partner->id,
            'status' => RevenueImportRowStatus::Matched,
            'error' => null,
            'amount' => $amount,
            'partner_name' => $partner->partner_name,
            'service_type' => $partner->service_type,
            'resolved_service_id' => $partner->service_id,
        ];
    }

    /**
     * @return array{revenue_partner_id: null, status: RevenueImportRowStatus, error: string, amount: ?float, partner_name: null, service_type: null, resolved_service_id: null}
     */
    protected function invalid(string $error, ?float $amount): array
    {
        return [
            'revenue_partner_id' => null,
            'status' => RevenueImportRowStatus::Invalid,
            'error' => $error,
            'amount' => $amount,
            'partner_name' => null,
            'service_type' => null,
            'resolved_service_id' => null,
        ];
    }

    protected function assertCanManage(?User $actor, RevenueImport $import): void
    {
        if (! $actor) {
            throw ValidationException::withMessages(['import' => 'Not authenticated.']);
        }
        if ($actor->canAccessAllRevenue() || (int) $import->created_by_user_id === (int) $actor->id) {
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
