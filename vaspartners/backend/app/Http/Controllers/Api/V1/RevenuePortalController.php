<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BulkMessageRecipientStatus;
use App\Enums\RevenueImportStatus;
use App\Http\Controllers\Controller;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Models\Contact;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Services\CompanyMembershipService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

class RevenuePortalController extends Controller
{
    /**
     * Partner-facing revenue ledger for the current company.
     * Scoped strictly by the company phone (last 9 digits) so partners only see
     * their own revenue rows. SMS status comes from linked bulk-message campaigns.
     */
    public function index(Request $request, CompanyMembershipService $membership)
    {
        /** @var Contact $contact */
        $contact = $request->user();

        try {
            $membership->assertCanAccessCompany($contact);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?: 'Complete and get your company TIN approved before viewing revenue.';

            return response()->json([
                'data' => [],
                'message' => $message,
            ]);
        }

        $companyId = (int) $contact->current_company_id;
        /** @var Company|null $company */
        $company = Company::query()->find($companyId);
        if (! $company) {
            return response()->json(['data' => [], 'message' => 'Company not found.']);
        }

        $companyPhone = PhoneNumber::normalizeNullable($company->phone);
        if ($companyPhone === null) {
            return response()->json([
                'data' => [],
                'message' => 'This company has no phone on file. Revenue is matched by company phone — ask an administrator to set it.',
            ]);
        }

        $partnerIds = $this->partnerIdsForCompanyPhone($companyPhone);
        if ($partnerIds === []) {
            return response()->json([
                'data' => [],
                'message' => 'No revenue partners match this company phone yet.',
            ]);
        }

        $rows = RevenueImportRow::query()
            ->with([
                'import:id,public_id,title,period,status,bulk_message_id,sent_at,imported_at',
                'partner:id,public_id,service_id,partner_name,phone,company_id,vas_service_id',
                'partner.vasService:id,name',
                'vasService:id,name',
            ])
            ->whereIn('revenue_partner_id', $partnerIds)
            ->whereHas('partner', function ($q) use ($companyPhone): void {
                // Defense in depth: never return a row whose partner phone does not match.
                $q->where(function ($phoneQ) use ($companyPhone): void {
                    $phoneQ->where('phone', $companyPhone)
                        ->orWhereRaw(
                            "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                            [$companyPhone],
                        );
                });
            })
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->whereHas('import', function ($q): void {
                $q->whereIn('status', [
                    RevenueImportStatus::Reviewing->value,
                    RevenueImportStatus::Ready->value,
                    RevenueImportStatus::Sending->value,
                    RevenueImportStatus::Completed->value,
                    RevenueImportStatus::Failed->value,
                ]);
            })
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $smsByKey = $this->smsStatusIndex($rows, $companyPhone);

        $data = $rows->map(function (RevenueImportRow $row) use ($smsByKey) {
            $import = $row->import;
            $partner = $row->partner;
            $key = ($import?->bulk_message_id ?: 0).'|'.(string) $row->service_id;
            $sms = $smsByKey[$key] ?? null;

            return [
                'id' => $row->id,
                'period' => $import?->period,
                'import_title' => $import?->title,
                'service_id' => $row->service_id,
                'partner_name' => $partner?->partner_name ?: $row->partner_name,
                'service_type' => $row->vasService?->name
                    ?: $partner?->vasService?->name,
                'amount' => $row->amount !== null ? (float) $row->amount : null,
                'amount_formatted' => $row->amount !== null
                    ? number_format((float) $row->amount, 2, '.', ',')
                    : null,
                'imported_at' => $import?->imported_at?->toIso8601String(),
                'sent_at' => $import?->sent_at?->toIso8601String(),
                'sms_status' => $sms['status'] ?? ($import?->bulk_message_id ? 'pending' : 'not_sent'),
                'sms_error' => $sms['error'] ?? null,
                'sms_sent_at' => $sms['sent_at'] ?? null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Revenue partners visible to a portal company — matched by company phone only.
     *
     * @return list<int>
     */
    protected function partnerIdsForCompanyPhone(string $companyPhone): array
    {
        return RevenuePartner::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyPhone): void {
                $q->where('phone', $companyPhone)
                    ->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$companyPhone],
                    );
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RevenueImportRow>  $rows
     * @return array<string, array{status: string, error: ?string, sent_at: ?string}>
     */
    protected function smsStatusIndex($rows, string $companyPhone): array
    {
        $campaignIds = $rows
            ->map(fn (RevenueImportRow $r) => $r->import?->bulk_message_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($campaignIds === []) {
            return [];
        }

        $serviceIds = $rows
            ->pluck('service_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $recipients = BulkMessageRecipient::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where(function ($q) use ($companyPhone): void {
                $q->where('phone_normalized', $companyPhone)
                    ->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone_normalized, phone_raw, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$companyPhone],
                    );
            })
            ->get(['campaign_id', 'status', 'error', 'sent_at', 'variables', 'phone_normalized']);

        $index = [];
        foreach ($recipients as $recipient) {
            $vars = is_array($recipient->variables) ? $recipient->variables : [];
            $serviceId = isset($vars['service_id']) ? (string) $vars['service_id'] : '';
            if ($serviceId === '' || ! in_array($serviceId, $serviceIds, true)) {
                continue;
            }

            $status = $recipient->status instanceof BulkMessageRecipientStatus
                ? $recipient->status->value
                : (string) $recipient->status;

            $key = $recipient->campaign_id.'|'.$serviceId;
            // Prefer failed/sent over pending when duplicates exist.
            $existing = $index[$key]['status'] ?? null;
            if ($existing === 'failed' || $existing === 'sent') {
                if ($status === 'pending' || $status === 'skipped') {
                    continue;
                }
            }

            $index[$key] = [
                'status' => $status,
                'error' => $recipient->error,
                'sent_at' => $recipient->sent_at?->toIso8601String(),
            ];
        }

        return $index;
    }
}
