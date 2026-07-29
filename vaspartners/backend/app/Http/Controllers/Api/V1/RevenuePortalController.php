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
use App\Support\PartnerCompanyNameMatcher;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

class RevenuePortalController extends Controller
{
    /**
     * Partner-facing revenue ledger for the current company.
     *
     * Scope (Abay often reuses one SMS contact phone across many partners):
     *  - revenue_partners.company_id = this company, or
     *  - same company phone AND partner_name ≈ company name, or
     *  - same company phone when that phone is unique on the master list.
     */
    public function index(Request $request, CompanyMembershipService $membership)
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sms_status' => ['nullable', 'string', 'in:all,failed,sent,not_sent'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 15);
        $smsFilter = $filters['sms_status'] ?? 'all';

        /** @var Contact $contact */
        $contact = $request->user();

        try {
            $membership->assertCanAccessCompany($contact);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?: 'Complete and get your company TIN approved before viewing revenue.';

            return $this->emptyPage($message, $perPage);
        }

        $companyId = (int) $contact->current_company_id;
        /** @var Company|null $company */
        $company = Company::query()->find($companyId);
        if (! $company) {
            return $this->emptyPage('Company not found.', $perPage);
        }

        $companyPhone = PhoneNumber::normalizeNullable($company->phone);
        $partnerIds = $this->partnerIdsForCompany($company, $companyPhone);
        if ($partnerIds === []) {
            return $this->emptyPage(
                $companyPhone === null
                    ? 'This company has no phone on file and no linked revenue partners yet.'
                    : 'No revenue partners match this company yet (phone + partner name).',
                $perPage,
            );
        }

        $query = RevenueImportRow::query()
            ->with([
                'import:id,public_id,title,period,status,bulk_message_id,sent_at,imported_at',
                'partner:id,public_id,service_id,partner_name,phone,company_id,vas_service_id',
                'partner.vasService:id,name',
                'vasService:id,name',
            ])
            ->whereIn('revenue_partner_id', $partnerIds)
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
            ->orderByDesc('id');

        // SMS filter needs recipient status — page after enrichment when filtered.
        if ($smsFilter === 'all') {
            $page = $query->paginate($perPage);
            $rows = $page->getCollection();
            $smsByKey = $this->smsStatusIndex($rows, $companyPhone);
            $page->setCollection($this->mapRows($rows, $smsByKey));

            return response()->json($page);
        }

        $allRows = $query->limit(500)->get();
        $smsByKey = $this->smsStatusIndex($allRows, $companyPhone);
        $mapped = $this->mapRows($allRows, $smsByKey)->values();

        $filtered = $mapped->filter(function (array $row) use ($smsFilter): bool {
            $status = (string) ($row['sms_status'] ?? 'not_sent');
            if ($smsFilter === 'not_sent') {
                return in_array($status, ['not_sent', 'pending'], true);
            }

            return $status === $smsFilter;
        })->values();

        $pageNum = max(1, (int) ($filters['page'] ?? 1));
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($pageNum > $lastPage) {
            $pageNum = $lastPage;
        }
        $slice = $filtered->slice(($pageNum - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'current_page' => $pageNum,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total === 0 ? null : (($pageNum - 1) * $perPage) + 1,
            'to' => $total === 0 ? null : (($pageNum - 1) * $perPage) + $slice->count(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RevenueImportRow>  $rows
     * @param  array<string, array{status: string, error: ?string, sent_at: ?string, phone: ?string}>  $smsByKey
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function mapRows($rows, array $smsByKey)
    {
        return $rows->map(function (RevenueImportRow $row) use ($smsByKey) {
            $import = $row->import;
            $partner = $row->partner;
            $key = ($import?->bulk_message_id ?: 0).'|'.(string) $row->service_id;
            $sms = $smsByKey[$key] ?? null;

            $partnerPhone = PhoneNumber::normalizeNullable($partner?->phone);
            $smsPhone = PhoneNumber::normalizeNullable($sms['phone'] ?? null) ?: $partnerPhone;

            return [
                'id' => $row->id,
                'period' => $import?->period,
                'import_title' => $import?->title,
                'service_id' => $row->service_id,
                'partner_name' => $partner?->partner_name ?: $row->partner_name,
                'service_type' => $row->vasService?->name
                    ?: $partner?->vasService?->name,
                'phone' => $partnerPhone,
                'sms_phone' => $smsPhone,
                'sms_phone_display' => $smsPhone
                    ? (PhoneNumber::toE164($smsPhone) ?: $smsPhone)
                    : null,
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
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function emptyPage(string $message, int $perPage)
    {
        return response()->json([
            'data' => [],
            'message' => $message,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ]);
    }

    /**
     * Revenue partners visible to a portal company.
     *
     * @return list<int>
     */
    protected function partnerIdsForCompany(Company $company, ?string $companyPhone): array
    {
        $ids = RevenuePartner::query()
            ->where('is_active', true)
            ->where('company_id', $company->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($companyPhone === null) {
            return array_values(array_unique($ids));
        }

        $phonePartners = RevenuePartner::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyPhone): void {
                $q->where('phone', $companyPhone)
                    ->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$companyPhone],
                    );
            })
            ->get(['id', 'partner_name', 'phone', 'company_id']);

        if ($phonePartners->isEmpty()) {
            return array_values(array_unique($ids));
        }

        $matched = $phonePartners
            ->filter(fn (RevenuePartner $p) => PartnerCompanyNameMatcher::matches(
                $p->partner_name,
                $company->name,
            ))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Unique phone on the master list → safe even when names diverge.
        if ($matched === [] && $phonePartners->count() === 1) {
            $matched = [(int) $phonePartners->first()->id];
        }

        return array_values(array_unique([...$ids, ...$matched]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RevenueImportRow>  $rows
     * @return array<string, array{status: string, error: ?string, sent_at: ?string, phone: ?string}>
     */
    protected function smsStatusIndex($rows, ?string $companyPhone): array
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

        $recipientsQuery = BulkMessageRecipient::query()
            ->whereIn('campaign_id', $campaignIds);

        if ($companyPhone !== null) {
            $recipientsQuery->where(function ($q) use ($companyPhone): void {
                $q->where('phone_normalized', $companyPhone)
                    ->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone_normalized, phone_raw, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$companyPhone],
                    );
            });
        }

        $recipients = $recipientsQuery->get([
            'campaign_id',
            'status',
            'error',
            'sent_at',
            'variables',
            'phone_normalized',
            'phone_raw',
        ]);

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

            $phone = PhoneNumber::normalizeNullable($recipient->phone_normalized)
                ?: PhoneNumber::normalizeNullable($recipient->phone_raw);

            $index[$key] = [
                'status' => $status,
                'error' => $recipient->error,
                'sent_at' => $recipient->sent_at?->toIso8601String(),
                'phone' => $phone,
            ];
        }

        return $index;
    }
}
