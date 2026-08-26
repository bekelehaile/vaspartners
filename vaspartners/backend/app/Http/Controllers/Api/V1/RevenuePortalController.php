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
     *  - same company phone or revenue phone AND partner_name ≈ company name, or
     *  - same company/revenue phone when that phone is unique on the master list.
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
                ?: 'Confirm your company TIN number with ERCA before viewing revenue.';

            return $this->emptyPage($message, $perPage);
        }

        $companyId = (int) $contact->current_company_id;
        /** @var Company|null $company */
        $company = Company::query()->find($companyId);
        if (! $company) {
            return $this->emptyPage('Company not found.', $perPage);
        }

        $matchPhones = $this->companyMatchPhones($company);
        $partnerIds = $this->partnerIdsForCompany($company, $matchPhones);
        if ($partnerIds === []) {
            return $this->emptyPage(
                $matchPhones === []
                    ? 'This company has no company or revenue phone on file and no linked revenue partners yet.'
                    : 'No revenue partners match this company yet (phone + partner name).',
                $perPage,
            );
        }

        $partners = RevenuePartner::query()
            ->whereIn('id', $partnerIds)
            ->get(['id', 'public_id', 'service_id', 'partner_name', 'phone', 'company_id', 'vas_service_id']);
        $serviceIds = $partners
            ->pluck('service_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $partnerPhones = $partners
            ->map(fn (RevenuePartner $p) => PhoneNumber::normalizeNullable($p->phone))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $smsPhones = array_values(array_unique([...$matchPhones, ...$partnerPhones]));
        $partnersByServiceId = $this->indexPartnersByServiceId($partners);

        $query = RevenueImportRow::query()
            ->with([
                'import:id,public_id,title,period,status,bulk_message_id,sent_at,imported_at',
                'partner:id,public_id,service_id,partner_name,phone,company_id,vas_service_id',
                'partner.vasService:id,name',
                'vasService:id,name',
            ])
            // Most import rows are unmatched (null revenue_partner_id); also accept
            // orphans whose service_id belongs to a phone/company-matched partner.
            ->where(function ($q) use ($partnerIds, $serviceIds): void {
                $q->whereIn('revenue_partner_id', $partnerIds);
                if ($serviceIds !== []) {
                    $q->orWhere(function ($inner) use ($serviceIds): void {
                        $inner->whereNull('revenue_partner_id')
                            ->where(function ($sidQ) use ($serviceIds): void {
                                $sidQ->whereIn('service_id', $serviceIds);
                                foreach ($serviceIds as $sid) {
                                    if (! preg_match('/^\d{10,}$/', $sid)) {
                                        continue;
                                    }
                                    $stripped = ltrim($sid, '0');
                                    if ($stripped !== '') {
                                        $sidQ->orWhereRaw(
                                            "NULLIF(LTRIM(service_id, '0'), '') = ?",
                                            [$stripped],
                                        );
                                    }
                                }
                            });
                    });
                }
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
            ->orderByDesc('id');

        // SMS filter needs recipient status — page after enrichment when filtered.
        if ($smsFilter === 'all') {
            $page = $query->paginate($perPage);
            $rows = $this->hydratePartners($page->getCollection(), $partnersByServiceId);
            $smsByKey = $this->smsStatusIndex($rows, $smsPhones);
            $page->setCollection($this->mapRows($rows, $smsByKey));

            return response()->json($page);
        }

        $allRows = $this->hydratePartners($query->limit(500)->get(), $partnersByServiceId);
        $smsByKey = $this->smsStatusIndex($allRows, $smsPhones);
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
     * Distinct phones used to scope portal revenue: claim/company phone and revenue phone.
     *
     * @return list<string>
     */
    protected function companyMatchPhones(Company $company): array
    {
        $phones = [];
        foreach ([
            PhoneNumber::normalizeNullable($company->claimPhone()),
            PhoneNumber::normalizeNullable($company->phone),
            PhoneNumber::normalizeNullable($company->revenuePhone()),
            PhoneNumber::normalizeNullable($company->revenue_phone),
        ] as $phone) {
            if ($phone !== null && $phone !== '') {
                $phones[$phone] = true;
            }
        }

        return array_keys($phones);
    }

    /**
     * Attach matched partners onto orphan import rows (null revenue_partner_id) by service_id.
     *
     * @param  \Illuminate\Support\Collection<int, RevenueImportRow>  $rows
     * @param  \Illuminate\Support\Collection<string, RevenuePartner>  $partnersByServiceId
     * @return \Illuminate\Support\Collection<int, RevenueImportRow>
     */
    protected function hydratePartners($rows, $partnersByServiceId)
    {
        foreach ($rows as $row) {
            if ($row->partner) {
                continue;
            }

            $serviceId = trim((string) ($row->service_id ?? ''));
            if ($serviceId === '') {
                continue;
            }

            $partner = $partnersByServiceId->get($serviceId);
            if (! $partner && preg_match('/^\d{10,}$/', $serviceId)) {
                $stripped = ltrim($serviceId, '0');
                if ($stripped !== '') {
                    $partner = $partnersByServiceId->get($stripped);
                }
            }

            if ($partner) {
                $row->setRelation('partner', $partner);
            }
        }

        return $rows;
    }

    /**
     * Index partners by service_id and zero-stripped form for orphan row hydration.
     *
     * @param  \Illuminate\Support\Collection<int, RevenuePartner>  $partners
     * @return \Illuminate\Support\Collection<string, RevenuePartner>
     */
    protected function indexPartnersByServiceId($partners)
    {
        $index = collect();
        foreach ($partners as $partner) {
            $sid = trim((string) ($partner->service_id ?? ''));
            if ($sid === '') {
                continue;
            }

            $index->put($sid, $partner);
            if (preg_match('/^\d{10,}$/', $sid)) {
                $stripped = ltrim($sid, '0');
                if ($stripped !== '') {
                    $index->put($stripped, $partner);
                }
            }
        }

        return $index;
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
     * @param  list<string>  $matchPhones
     * @return list<int>
     */
    protected function partnerIdsForCompany(Company $company, array $matchPhones): array
    {
        $ids = RevenuePartner::query()
            ->where('is_active', true)
            ->where('company_id', $company->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($matchPhones === []) {
            return array_values(array_unique($ids));
        }

        $phonePartners = RevenuePartner::query()
            ->where('is_active', true)
            ->where(function ($q) use ($matchPhones): void {
                $q->whereIn('phone', $matchPhones);
                foreach ($matchPhones as $phone) {
                    $q->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$phone],
                    );
                }
            })
            ->get(['id', 'partner_name', 'phone', 'company_id']);

        if ($phonePartners->isEmpty()) {
            return array_values(array_unique($ids));
        }

        // Per phone: name match, or unique master-list phone when names diverge.
        $matched = [];
        $byPhone = $phonePartners->groupBy(
            fn (RevenuePartner $p) => PhoneNumber::normalizeNullable($p->phone) ?: (string) $p->id,
        );
        foreach ($byPhone as $group) {
            $nameMatched = $group
                ->filter(fn (RevenuePartner $p) => PartnerCompanyNameMatcher::matches(
                    $p->partner_name,
                    $company->name,
                ))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($nameMatched !== []) {
                array_push($matched, ...$nameMatched);
            } elseif ($group->count() === 1) {
                $matched[] = (int) $group->first()->id;
            }
        }

        return array_values(array_unique([...$ids, ...$matched]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RevenueImportRow>  $rows
     * @param  list<string>  $matchPhones
     * @return array<string, array{status: string, error: ?string, sent_at: ?string, phone: ?string}>
     */
    protected function smsStatusIndex($rows, array $matchPhones): array
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

        if ($matchPhones !== []) {
            $recipientsQuery->where(function ($q) use ($matchPhones): void {
                $q->whereIn('phone_normalized', $matchPhones);
                foreach ($matchPhones as $phone) {
                    $q->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone_normalized, phone_raw, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [$phone],
                    );
                }
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
