<?php

namespace App\Services;

use App\Enums\CompanyRole;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Models\CompanyChangeRequest;
use App\Models\CompanyMembership;
use App\Models\CompanyStatusHistory;
use App\Models\Feedback;
use App\Models\RevenuePartner;
use App\Models\Subscription;
use App\Support\TinNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merge abandoned MVAS / unverified placeholder companies into live ERCA TIN-verified companies.
 * Moves subscriptions, memberships, change requests, history, feedback, revenue partners, and bulk recipients.
 */
class ConsolidateMvasIntoVerifiedTinService
{
    /**
     * @return array{pairs: int, moved: array<string, int>, soft_deleted: int, rows: list<array<string, mixed>>}
     */
    public function consolidateAll(bool $dryRun = false): array
    {
        $moved = [
            'subscriptions' => 0,
            'memberships' => 0,
            'change_requests' => 0,
            'status_histories' => 0,
            'feedback' => 0,
            'revenue_partners' => 0,
            'bulk_recipients' => 0,
            'legacy_copied' => 0,
        ];
        $softDeleted = 0;
        $rows = [];

        foreach ($this->discoverPairs() as $pair) {
            $result = $this->consolidatePair(
                (int) $pair->old_id,
                (int) $pair->new_id,
                $dryRun,
            );
            foreach ($result['moved'] as $key => $count) {
                $moved[$key] = ($moved[$key] ?? 0) + $count;
            }
            $softDeleted += $result['soft_deleted'] ? 1 : 0;
            $rows[] = $result['row'];
        }

        return [
            'pairs' => count($rows),
            'moved' => $moved,
            'soft_deleted' => $softDeleted,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<object{
     *   old_id: int|string,
     *   new_id: int|string,
     *   old_tin: string,
     *   new_tin: string,
     *   old_name: string,
     *   new_name: string
     * }>
     */
    public function discoverPairs(): array
    {
        // Primary: remounted (or still linked) subscriptions share legacy_mvas_id with MVAS company.
        $byLegacy = DB::select("
            SELECT DISTINCT
                oldc.id AS old_id,
                oldc.tin AS old_tin,
                oldc.name AS old_name,
                v.id AS new_id,
                v.tin AS new_tin,
                v.name AS new_name
            FROM companies v
            JOIN company_memberships om
                ON om.company_id = v.id AND om.role = ? AND om.is_active = true
            JOIN subscriptions s
                ON s.contact_id = om.contact_id
                AND s.company_id = v.id
                AND s.deleted_at IS NULL
                AND s.legacy_mvas_id IS NOT NULL
            JOIN companies oldc
                ON oldc.deleted_at IS NULL
                AND oldc.tin LIKE 'MVAS-%'
                AND oldc.legacy_mvas_id = s.legacy_mvas_id
                AND oldc.id <> v.id
            WHERE v.deleted_at IS NULL
              AND v.tin_validated = true
              AND v.erca_tin_verified = true
              AND v.tin ~ '^[0-9]{10}$'
        ", [CompanyRole::Owner->value]);

        // Secondary: placeholder created_by contact is now owner of a verified company.
        $byCreator = DB::select("
            SELECT DISTINCT
                oldc.id AS old_id,
                oldc.tin AS old_tin,
                oldc.name AS old_name,
                v.id AS new_id,
                v.tin AS new_tin,
                v.name AS new_name
            FROM companies oldc
            JOIN company_memberships nm
                ON nm.contact_id = oldc.created_by_contact_id
                AND nm.role = ?
                AND nm.is_active = true
            JOIN companies v
                ON v.id = nm.company_id
                AND v.deleted_at IS NULL
                AND v.tin_validated = true
                AND v.erca_tin_verified = true
                AND v.tin ~ '^[0-9]{10}$'
                AND v.id <> oldc.id
            WHERE oldc.deleted_at IS NULL
              AND oldc.tin LIKE 'MVAS-%'
              AND oldc.created_by_contact_id IS NOT NULL
        ", [CompanyRole::Owner->value]);

        // Tertiary: unique normalized name / legal_name match (abandoned MVAS with no membership link).
        $byName = DB::select("
            WITH norm AS (
                SELECT
                    id,
                    tin,
                    name,
                    regexp_replace(lower(trim(name)), '[^a-z0-9]+', '', 'g') AS n_name,
                    regexp_replace(lower(trim(COALESCE(legal_name, ''))), '[^a-z0-9]+', '', 'g') AS n_legal,
                    tin_validated,
                    erca_tin_verified
                FROM companies
                WHERE deleted_at IS NULL
            ),
            candidates AS (
                SELECT
                    o.id AS old_id,
                    o.tin AS old_tin,
                    o.name AS old_name,
                    v.id AS new_id,
                    v.tin AS new_tin,
                    v.name AS new_name,
                    COUNT(*) OVER (PARTITION BY o.id) AS match_count
                FROM norm o
                JOIN norm v
                    ON v.id <> o.id
                    AND v.tin_validated = true
                    AND v.erca_tin_verified = true
                    AND v.tin ~ '^[0-9]{10}$'
                    AND o.n_name <> ''
                    AND (
                        v.n_name = o.n_name
                        OR (v.n_legal <> '' AND v.n_legal = o.n_name)
                    )
                WHERE o.tin LIKE 'MVAS-%'
            )
            SELECT old_id, old_tin, old_name, new_id, new_tin, new_name
            FROM candidates
            WHERE match_count = 1
        ");

        $keyed = [];
        foreach (array_merge($byLegacy, $byCreator, $byName) as $row) {
            $key = ((int) $row->old_id).':'.((int) $row->new_id);
            $keyed[$key] = $row;
        }

        $pairs = array_values($keyed);
        usort($pairs, fn ($a, $b) => strcmp((string) $a->new_name, (string) $b->new_name));

        return $pairs;
    }

    /**
     * Soft-delete abandoned MVAS placeholders with no memberships, live subscriptions, or change requests.
     *
     * @return array{pruned: int, dry_run: bool}
     */
    public function pruneEmptyShells(bool $dryRun = false): array
    {
        $query = Company::query()
            ->where('tin', 'like', 'MVAS-%')
            ->whereDoesntHave('memberships')
            ->whereDoesntHave('subscriptions')
            ->whereDoesntHave('changeRequests');

        $ids = $query->pluck('id');
        $count = $ids->count();

        if ($dryRun || $count === 0) {
            return ['pruned' => $count, 'dry_run' => $dryRun];
        }

        $pruned = 0;
        foreach ($ids->chunk(200) as $chunk) {
            $pruned += Company::query()
                ->whereIn('id', $chunk->all())
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                    'deleted_at' => now(),
                ]);
        }

        Log::info('Pruned empty MVAS company shells', ['pruned' => $pruned]);

        return ['pruned' => $pruned, 'dry_run' => false];
    }

    /**
     * @return array{moved: array<string, int>, soft_deleted: bool, row: array<string, mixed>}
     */
    public function consolidatePair(int $oldCompanyId, int $newCompanyId, bool $dryRun = false): array
    {
        $old = Company::withTrashed()->findOrFail($oldCompanyId);
        $new = Company::query()->findOrFail($newCompanyId);

        if (! TinNumber::isValid($new->tin) || ! $new->tin_validated || ! $new->erca_tin_verified) {
            return [
                'moved' => [],
                'soft_deleted' => false,
                'row' => [
                    'old_tin' => $old->tin,
                    'new_tin' => $new->tin,
                    'new_name' => $new->name,
                    'action' => 'skip_target_not_verified',
                ],
            ];
        }

        $counts = [
            'subscriptions' => 0,
            'memberships' => 0,
            'change_requests' => 0,
            'status_histories' => 0,
            'feedback' => 0,
            'revenue_partners' => 0,
            'bulk_recipients' => 0,
            'legacy_copied' => 0,
        ];

        if ($dryRun) {
            $counts['subscriptions'] = Subscription::withTrashed()->where('company_id', $old->id)->count();
            $counts['memberships'] = CompanyMembership::query()->where('company_id', $old->id)->count();
            $counts['change_requests'] = CompanyChangeRequest::withTrashed()->where('company_id', $old->id)->count();
            $counts['status_histories'] = CompanyStatusHistory::query()->where('company_id', $old->id)->count();
            $counts['feedback'] = Feedback::query()->where('company_id', $old->id)->count();
            $counts['revenue_partners'] = RevenuePartner::query()->where('company_id', $old->id)->count();
            $counts['bulk_recipients'] = BulkMessageRecipient::query()->where('company_id', $old->id)->count();
            $counts['legacy_copied'] = blank($new->legacy_mvas_id) && filled($old->legacy_mvas_id) ? 1 : 0;

            return [
                'moved' => $counts,
                'soft_deleted' => ! $old->trashed(),
                'row' => [
                    'old_tin' => $old->tin,
                    'new_tin' => $new->tin,
                    'new_name' => $new->name,
                    'action' => 'would_consolidate',
                    'counts' => $counts,
                ],
            ];
        }

        DB::transaction(function () use ($old, $new, &$counts) {
            // Subscriptions (including soft-deleted rows for history).
            $counts['subscriptions'] = Subscription::withTrashed()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id, 'updated_at' => now()]);

            // Memberships: move if contact not already on target; otherwise drop duplicate.
            $memberships = CompanyMembership::query()->where('company_id', $old->id)->get();
            foreach ($memberships as $membership) {
                $exists = CompanyMembership::query()
                    ->where('company_id', $new->id)
                    ->where('contact_id', $membership->contact_id)
                    ->exists();
                if ($exists) {
                    $membership->delete();
                } else {
                    $membership->forceFill(['company_id' => $new->id])->save();
                    $counts['memberships']++;
                }
            }

            $counts['change_requests'] = CompanyChangeRequest::withTrashed()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id, 'updated_at' => now()]);

            $counts['status_histories'] = CompanyStatusHistory::query()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id]);

            $counts['feedback'] = Feedback::query()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id, 'updated_at' => now()]);

            $counts['revenue_partners'] = RevenuePartner::query()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id, 'updated_at' => now()]);

            $counts['bulk_recipients'] = BulkMessageRecipient::query()
                ->where('company_id', $old->id)
                ->update(['company_id' => $new->id, 'updated_at' => now()]);

            if (filled($old->legacy_mvas_id)) {
                $legacy = $old->legacy_mvas_id;
                // Unique index — free the placeholder before assigning to the live company.
                $old->forceFill(['legacy_mvas_id' => null])->save();

                if (blank($new->legacy_mvas_id)
                    && ! Company::withTrashed()
                        ->where('legacy_mvas_id', $legacy)
                        ->where('id', '!=', $new->id)
                        ->exists()) {
                    $new->forceFill(['legacy_mvas_id' => $legacy])->save();
                    $counts['legacy_copied'] = 1;
                }
            }

            CompanyStatusHistory::query()->create([
                'company_id' => $new->id,
                'action' => 'mvas_consolidated',
                'actor_user_id' => null,
                'actor_contact_id' => null,
                'note' => 'Merged placeholder company '.$old->tin.' into TIN-verified company',
                'meta' => [
                    'from_company_id' => $old->id,
                    'from_tin' => $old->tin,
                    'to_tin' => $new->tin,
                ],
                'created_at' => now(),
            ]);

            if (! $old->trashed()) {
                $old->forceFill([
                    'is_active' => false,
                    'name' => '[Merged] '.$old->name,
                ])->save();
                $old->delete();
            }
        });

        Log::info('MVAS company consolidated into TIN-verified company', [
            'from_company_id' => $old->id,
            'from_tin' => $old->tin,
            'to_company_id' => $new->id,
            'to_tin' => $new->tin,
            'moved' => $counts,
        ]);

        return [
            'moved' => $counts,
            'soft_deleted' => true,
            'row' => [
                'old_tin' => $old->tin,
                'new_tin' => $new->tin,
                'new_name' => $new->name,
                'action' => 'consolidated',
                'counts' => $counts,
            ],
        ];
    }
}
