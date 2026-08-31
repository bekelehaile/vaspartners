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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RevenuePortalController extends Controller
{
    /**
     * Partner-facing revenue ledger for the current company.
     *
     * Rows come from revenue_import_rows (same data as Partner Revenue admin).
     * Matched when the master partner phone equals the company phone or revenue phone,
     * plus linked partner, service ID, and name fallbacks.
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
        $serviceIds = $this->serviceIdsForPartnerIds($partnerIds);
        $hasNameScope = PartnerCompanyNameMatcher::normalize($company->name) !== '';

        if ($partnerIds === [] && $matchPhones === [] && ! $hasNameScope) {
            return $this->emptyPage('This company has no phone or name we can match revenue against yet.', $perPage);
        }

        $smsPhones = $matchPhones;
        if ($partnerIds !== []) {
            $smsPhones = array_values(array_unique([
                ...$smsPhones,
                ...RevenuePartner::query()
                    ->whereIn('id', $partnerIds)
                    ->pluck('phone')
                    ->map(fn ($p) => PhoneNumber::normalizeNullable($p))
                    ->filter()
                    ->all(),
            ]));
        }

        $partnersByServiceId = $this->globalPartnersByServiceId();

        $query = RevenueImportRow::query()
            ->with([
                'import:id,public_id,title,period,status,bulk_message_id,sent_at,imported_at',
                'partner:id,public_id,service_id,partner_name,phone,company_id,vas_service_id',
                'partner.vasService:id,name',
                'vasService:id,name',
            ])
            ->where(function (Builder $q) use ($company, $partnerIds, $serviceIds, $matchPhones): void {
                $this->applyRowScopeForCompany($q, $company, $partnerIds, $serviceIds, $matchPhones);
            })
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->whereRaw("UPPER(COALESCE(service_id, '')) NOT LIKE 'TOTAL%'")
            ->whereRaw("UPPER(COALESCE(partner_name, '')) NOT LIKE '%TOTAL%'")
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
     * Company phone and revenue phone used to match master partners and revenue rows.
     *
     * @return list<string>
     */
    protected function companyMatchPhones(Company $company): array
    {
        $phones = [];
        foreach ([
            PhoneNumber::normalizeNullable($company->phone),
            PhoneNumber::normalizeNullable($company->claim_phone),
            PhoneNumber::normalizeNullable($company->revenue_phone),
        ] as $phone) {
            if ($phone !== null && $phone !== '') {
                $phones[$phone] = true;
            }
        }

        return array_keys($phones);
    }

    /**
     * @param  list<int>  $partnerIds
     * @param  list<string>  $serviceIds
     * @param  list<string>  $matchPhones
     */
    protected function applyRowScopeForCompany(
        Builder $query,
        Company $company,
        array $partnerIds,
        array $serviceIds,
        array $matchPhones,
    ): void {
        $query->where(function (Builder $q) use ($company, $partnerIds, $serviceIds, $matchPhones): void {
            $hasClause = false;

            if ($partnerIds !== []) {
                $q->whereIn('revenue_partner_id', $partnerIds);
                $hasClause = true;
            }

            if ($matchPhones !== []) {
                $method = $hasClause ? 'orWhere' : 'where';
                $q->{$method}(function (Builder $inner) use ($matchPhones): void {
                    $inner->whereHas('partner', function (Builder $partnerQuery) use ($matchPhones): void {
                        $this->applyPartnerPhoneScope($partnerQuery, $matchPhones);
                    });
                });
                $hasClause = true;
            }

            if ($serviceIds !== []) {
                $method = $hasClause ? 'orWhere' : 'where';
                $q->{$method}(function (Builder $inner) use ($serviceIds): void {
                    $this->applyServiceIdScope($inner, $serviceIds);
                });
                $hasClause = true;
            }

            $method = $hasClause ? 'orWhere' : 'where';
            $q->{$method}(function (Builder $inner) use ($company): void {
                $this->applyNormalizedNameScope($inner, 'partner_name', $company->name);
            });
        });
    }

    /**
     * Match revenue partner phones to company / revenue phone (exact or last 9 digits).
     *
     * @param  list<string>  $matchPhones
     */
    protected function applyPartnerPhoneScope(Builder $query, array $matchPhones): void
    {
        if ($matchPhones === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $phoneQuery) use ($matchPhones): void {
            $phoneQuery->whereIn('phone', $matchPhones);
            foreach ($matchPhones as $phone) {
                $phoneQuery->orWhereRaw(
                    "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                    [$phone],
                );
            }
        });
    }

    /**
     * @param  list<string>  $serviceIds
     */
    protected function applyServiceIdScope(Builder $query, array $serviceIds): void
    {
        $query->whereIn('service_id', $serviceIds);
        foreach ($serviceIds as $sid) {
            if (! preg_match('/^\d{10,}$/', $sid)) {
                continue;
            }
            $stripped = ltrim($sid, '0');
            if ($stripped !== '') {
                $query->orWhereRaw(
                    "NULLIF(LTRIM(service_id, '0'), '') = ?",
                    [$stripped],
                );
            }
        }
    }

    protected function applyNormalizedNameScope(Builder $query, string $column, ?string $companyName): void
    {
        $normalized = PartnerCompanyNameMatcher::normalize($companyName);
        if ($normalized === '' || strlen($normalized) < 4) {
            $query->whereRaw('1 = 0');

            return;
        }

        $sqlColumn = "REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '[^a-z0-9]', '', 'g')";
        $query->where(function (Builder $q) use ($sqlColumn, $normalized): void {
            $q->whereRaw("{$sqlColumn} LIKE ?", ['%'.$normalized.'%'])
                ->orWhereRaw("? LIKE '%' || {$sqlColumn} || '%'", [$normalized]);
        });
    }

    /**
     * @param  list<int>  $partnerIds
     * @return list<string>
     */
    protected function serviceIdsForPartnerIds(array $partnerIds): array
    {
        if ($partnerIds === []) {
            return [];
        }

        return RevenuePartner::query()
            ->whereIn('id', $partnerIds)
            ->pluck('service_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * All active partners indexed by service_id (for orphan row display).
     *
     * @return \Illuminate\Support\Collection<string, RevenuePartner>
     */
    protected function globalPartnersByServiceId()
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $partners = RevenuePartner::query()
            ->where('is_active', true)
            ->get(['id', 'public_id', 'service_id', 'partner_name', 'phone', 'company_id', 'vas_service_id']);

        $index = $this->indexPartnersByServiceId($partners);

        return $index;
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
     * Revenue partners for a portal company: linked, same phone, or similar name.
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

        $phoneOrNameQuery = RevenuePartner::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($matchPhones, $company): void {
                if ($matchPhones !== []) {
                    $q->where(function (Builder $phoneQ) use ($matchPhones): void {
                        $this->applyPartnerPhoneScope($phoneQ, $matchPhones);
                    });
                }

                $q->orWhere(function (Builder $nameQ) use ($company): void {
                    $this->applyNormalizedNameScope($nameQ, 'partner_name', $company->name);
                });
            });

        $extraIds = $phoneOrNameQuery
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$extraIds]));
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
