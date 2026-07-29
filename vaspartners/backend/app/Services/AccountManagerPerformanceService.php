<?php

namespace App\Services;

use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Account manager (handler) performance for the Reports section.
 *
 * Grain: current ticket assignee (`assigned_to_user_id`).
 *
 * Each KPI uses its matching event timestamp:
 * - Assigned  → assigned_at
 * - Completed → completed_at (status completed or closed — Completed is often transient before Close)
 * - Closed    → closed_at (status closed)
 * - Rejected  → rejected_at (status rejected)
 * - Cycle     → outcome timestamp − assigned_at (per outcome type)
 * - Pickup    → assigned_at − created_at
 * - Backlog   → live open/in_progress
 */
class AccountManagerPerformanceService
{
    /**
     * @param  array{start?: ?string, end?: ?string, service_id?: ?int, user_id?: ?int}  $filters
     * @return Collection<int, array{
     *   user_id: int,
     *   name: string,
     *   email: string,
     *   backlog: int,
     *   assigned_in_period: int,
     *   completed: int,
     *   closed: int,
     *   rejected: int,
     *   avg_cycle_hours: float|null,
     *   avg_pickup_hours: float|null,
     *   oldest_backlog_hours: float|null,
     *   doc_pass_rate: float|null,
     *   rejection_rate: float|null,
     *   completion_rate: float|null,
     *   throughput_score: float
     * }>
     */
    public function rows(array $filters = []): Collection
    {
        [$start, $end] = $this->resolvePeriod($filters['start'] ?? null, $filters['end'] ?? null);
        $serviceId = isset($filters['service_id']) ? (int) $filters['service_id'] : 0;
        $userId = isset($filters['user_id']) ? (int) $filters['user_id'] : 0;

        $open = TicketStatus::Open->value;
        $inProgress = TicketStatus::InProgress->value;
        $completed = TicketStatus::Completed->value;
        $closed = TicketStatus::Closed->value;
        $rejected = TicketStatus::Rejected->value;
        $passed = DocumentReviewStatus::Passed->value;
        $failed = DocumentReviewStatus::Failed->value;

        $query = Ticket::query()
            ->whereNotNull('assigned_to_user_id')
            ->when($serviceId > 0, fn ($q) => $q->where('service_id', $serviceId))
            ->when($userId > 0, fn ($q) => $q->where('assigned_to_user_id', $userId));

        $aggregates = $query
            ->select('assigned_to_user_id')
            // Live workload — not period-bound.
            ->selectRaw(
                'count(*) filter (where status in (?, ?)) as backlog',
                [$open, $inProgress],
            )
            // Assigned in period → assigned_at only.
            ->selectRaw(
                'count(*) filter (
                    where assigned_at is not null
                      and assigned_at >= ?
                      and assigned_at < ?
                ) as assigned_in_period',
                [$start, $end],
            )
            // Completed in period → completed_at (include already-closed; Completed is transient).
            ->selectRaw(
                'count(*) filter (
                    where status in (?, ?)
                      and completed_at is not null
                      and completed_at >= ?
                      and completed_at < ?
                ) as completed',
                [$completed, $closed, $start, $end],
            )
            // Still Completed (not closed yet) — for rates without double-counting closes.
            ->selectRaw(
                'count(*) filter (
                    where status = ?
                      and completed_at is not null
                      and completed_at >= ?
                      and completed_at < ?
                ) as completed_open',
                [$completed, $start, $end],
            )
            // Closed in period → status closed + closed_at.
            ->selectRaw(
                'count(*) filter (
                    where status = ?
                      and closed_at is not null
                      and closed_at >= ?
                      and closed_at < ?
                ) as closed',
                [$closed, $start, $end],
            )
            // Rejected in period → status + rejected_at.
            ->selectRaw(
                'count(*) filter (
                    where status = ?
                      and rejected_at is not null
                      and rejected_at >= ?
                      and rejected_at < ?
                ) as rejected',
                [$rejected, $start, $end],
            )
            // Cycle: each outcome uses its own end stamp vs assigned_at.
            ->selectRaw(
                'avg(
                    extract(epoch from (
                        case
                            when status = ? then completed_at
                            when status = ? then closed_at
                            when status = ? then rejected_at
                        end
                        - assigned_at
                    )) / 3600.0
                ) filter (
                    where assigned_at is not null
                      and (
                        (status = ? and completed_at is not null and completed_at >= ? and completed_at < ? and completed_at >= assigned_at)
                        or (status = ? and closed_at is not null and closed_at >= ? and closed_at < ? and closed_at >= assigned_at)
                        or (status = ? and rejected_at is not null and rejected_at >= ? and rejected_at < ? and rejected_at >= assigned_at)
                      )
                ) as avg_cycle_hours',
                [
                    $completed, $closed, $rejected,
                    $completed, $start, $end,
                    $closed, $start, $end,
                    $rejected, $start, $end,
                ],
            )
            // Pickup: create → assign (assigned_at window).
            ->selectRaw(
                'avg(extract(epoch from (assigned_at - created_at)) / 3600.0) filter (
                    where assigned_at is not null
                      and assigned_at >= ?
                      and assigned_at < ?
                      and assigned_at > created_at
                ) as avg_pickup_hours',
                [$start, $end],
            )
            // Oldest live backlog age from assigned_at (handler clock), else created_at.
            ->selectRaw(
                "max(extract(epoch from (now() - coalesce(assigned_at, in_progress_at, opened_at, created_at))) / 3600.0) filter (
                    where status in (?, ?)
                ) as oldest_backlog_hours",
                [$open, $inProgress],
            )
            // Doc quality on terminal approvals in period (completed or closed — each uses own stamp).
            ->selectRaw(
                'count(*) filter (
                    where document_review_status = ?
                      and (
                        (status = ? and completed_at is not null and completed_at >= ? and completed_at < ?)
                        or (status = ? and closed_at is not null and closed_at >= ? and closed_at < ?)
                      )
                ) as docs_passed',
                [$passed, $completed, $start, $end, $closed, $start, $end],
            )
            ->selectRaw(
                'count(*) filter (
                    where document_review_status = ?
                      and (
                        (status = ? and completed_at is not null and completed_at >= ? and completed_at < ?)
                        or (status = ? and closed_at is not null and closed_at >= ? and closed_at < ?)
                      )
                ) as docs_failed',
                [$failed, $completed, $start, $end, $closed, $start, $end],
            )
            ->groupBy('assigned_to_user_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_to_user_id);

        $userIds = $aggregates->keys()->all();
        if ($userIds === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return $aggregates
            ->map(function ($row) use ($users) {
                $user = $users->get((int) $row->assigned_to_user_id);
                if (! $user) {
                    return null;
                }

                $completedCount = (int) $row->completed;
                $completedOpen = (int) ($row->completed_open ?? 0);
                $rejectedCount = (int) $row->rejected;
                $closedCount = (int) $row->closed;
                // Rates: closed + still-completed + rejected (don't double-count closed-as-completed).
                $positive = $closedCount + $completedOpen;
                $outcomes = $positive + $rejectedCount;
                $docsReviewed = (int) $row->docs_passed + (int) $row->docs_failed;

                // Positive resolution = closed or still completed (rejected is the only negative outcome).
                $completionRate = $outcomes > 0 ? round(($positive / $outcomes) * 100, 1) : null;
                $rejectionRate = $outcomes > 0 ? round(($rejectedCount / $outcomes) * 100, 1) : null;
                $docPassRate = $docsReviewed > 0
                    ? round(((int) $row->docs_passed / $docsReviewed) * 100, 1)
                    : null;

                $avgCycle = $row->avg_cycle_hours !== null ? round((float) $row->avg_cycle_hours, 1) : null;
                $avgPickup = $row->avg_pickup_hours !== null ? round((float) $row->avg_pickup_hours, 1) : null;

                $throughputScore = $this->throughputScore(
                    backlog: (int) $row->backlog,
                    completed: $positive,
                    avgCycleHours: $avgCycle,
                    rejectionRate: $rejectionRate,
                    completionRate: $completionRate,
                );

                return [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) ($user->email ?: ''),
                    'backlog' => (int) $row->backlog,
                    'assigned_in_period' => (int) $row->assigned_in_period,
                    'completed' => $completedCount,
                    'closed' => $closedCount,
                    'rejected' => $rejectedCount,
                    'avg_cycle_hours' => $avgCycle,
                    'avg_pickup_hours' => $avgPickup,
                    'oldest_backlog_hours' => $row->oldest_backlog_hours !== null
                        ? round((float) $row->oldest_backlog_hours, 1)
                        : null,
                    'doc_pass_rate' => $docPassRate,
                    'rejection_rate' => $rejectionRate,
                    'completion_rate' => $completionRate,
                    'throughput_score' => $throughputScore,
                ];
            })
            ->filter()
            ->sortByDesc('throughput_score')
            ->values();
    }

    /**
     * Team-wide KPI strip for the report header.
     *
     * @param  array{start?: ?string, end?: ?string, service_id?: ?int, user_id?: ?int}  $filters
     * @return array{
     *   handlers: int,
     *   backlog: int,
     *   completed: int,
     *   closed: int,
     *   avg_cycle_hours: float|null,
     *   avg_pickup_hours: float|null,
     *   rejection_rate: float|null,
     *   unassigned_open: int
     * }
     */
    public function teamSummary(array $filters = []): array
    {
        $rows = $this->rows($filters);
        $serviceId = isset($filters['service_id']) ? (int) $filters['service_id'] : 0;

        $completed = (int) $rows->sum('completed');
        $rejected = (int) $rows->sum('rejected');
        $closed = (int) $rows->sum('closed');
        // Avoid double-counting: closed tickets may also appear in completed (completed_at).
        $outcomes = $closed + $rejected;
        $positive = $closed;

        $cycleWeightKey = fn (array $r): int => $r['closed'] + $r['rejected'] + ($r['completed'] > $r['closed'] ? $r['completed'] - $r['closed'] : 0);
        $cycleSamples = $rows->filter(
            fn (array $r) => $r['avg_cycle_hours'] !== null && ($r['closed'] + $r['rejected'] + $r['completed']) > 0,
        );
        $pickupSamples = $rows->filter(fn (array $r) => $r['avg_pickup_hours'] !== null && $r['assigned_in_period'] > 0);

        $weightedCycle = null;
        if ($cycleSamples->isNotEmpty()) {
            $weight = $cycleSamples->sum(fn (array $r) => max(1, $r['closed'] + $r['rejected']));
            $weightedCycle = $weight > 0
                ? round($cycleSamples->sum(fn (array $r) => $r['avg_cycle_hours'] * max(1, $r['closed'] + $r['rejected'])) / $weight, 1)
                : null;
        }

        $weightedPickup = null;
        if ($pickupSamples->isNotEmpty()) {
            $weight = $pickupSamples->sum('assigned_in_period');
            $weightedPickup = $weight > 0
                ? round($pickupSamples->sum(fn (array $r) => $r['avg_pickup_hours'] * $r['assigned_in_period']) / $weight, 1)
                : null;
        }

        // Live unassigned open queue (not period-created — that skewed the KPI).
        $unassigned = Ticket::query()
            ->where('status', TicketStatus::Open)
            ->whereNull('assigned_to_user_id')
            ->when($serviceId > 0, fn ($q) => $q->where('service_id', $serviceId))
            ->count();

        return [
            'handlers' => $rows->count(),
            'backlog' => (int) $rows->sum('backlog'),
            'completed' => $completed,
            'closed' => $closed,
            'rejected' => $rejected,
            'avg_cycle_hours' => $weightedCycle,
            'avg_pickup_hours' => $weightedPickup,
            'rejection_rate' => $outcomes > 0 ? round(($rejected / $outcomes) * 100, 1) : null,
            'completion_rate' => $outcomes > 0 ? round(($positive / $outcomes) * 100, 1) : null,
            'unassigned_open' => $unassigned,
        ];
    }

    /**
     * @param  array{start?: ?string, end?: ?string, service_id?: ?int, user_id?: ?int}  $filters
     */
    public function toCsv(array $filters = []): string
    {
        $rows = $this->rows($filters);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, [
            'Rank',
            'Account handler',
            'Email',
            'Throughput score',
            'Backlog (open/in progress)',
            'Assigned in period (assigned_at)',
            'Completed (completed_at)',
            'Closed (closed_at)',
            'Rejected (rejected_at)',
            'Completion rate %',
            'Rejection rate %',
            'Avg cycle hours (assign→outcome)',
            'Avg pickup hours (create→assign)',
            'Oldest backlog hours',
            'Doc pass rate %',
        ]);

        foreach ($rows->values() as $index => $row) {
            fputcsv($out, [
                $index + 1,
                $row['name'],
                $row['email'],
                $row['throughput_score'],
                $row['backlog'],
                $row['assigned_in_period'],
                $row['completed'],
                $row['closed'],
                $row['rejected'],
                $row['completion_rate'] ?? '',
                $row['rejection_rate'] ?? '',
                $row['avg_cycle_hours'] ?? '',
                $row['avg_pickup_hours'] ?? '',
                $row['oldest_backlog_hours'] ?? '',
                $row['doc_pass_rate'] ?? '',
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }

    /**
     * @return array{0: Carbon, 1: Carbon} [inclusive start, exclusive end)
     */
    public function resolvePeriod(?string $start, ?string $end): array
    {
        $from = filled($start)
            ? Carbon::parse($start)->startOfDay()
            : now()->subMonth()->startOfDay();
        $toExclusive = filled($end)
            ? Carbon::parse($end)->addDay()->startOfDay()
            : now()->addDay()->startOfDay();

        if ($toExclusive->lte($from)) {
            $toExclusive = (clone $from)->addDay();
        }

        return [$from, $toExclusive];
    }

    protected function throughputScore(
        int $backlog,
        int $completed,
        ?float $avgCycleHours,
        ?float $rejectionRate,
        ?float $completionRate,
    ): float {
        $score = 50.0;
        $score += min(40.0, $completed * 4.0);
        $score -= min(25.0, $backlog * 2.5);

        if ($avgCycleHours !== null) {
            // Faster cycle → higher score (soft cap).
            $score += max(-15.0, min(15.0, (48.0 - $avgCycleHours) / 4.0));
        }

        if ($rejectionRate !== null) {
            $score -= min(20.0, $rejectionRate / 5.0);
        }

        if ($completionRate !== null) {
            $score += ($completionRate - 50.0) / 5.0;
        }

        return round(max(0.0, min(100.0, $score)), 1);
    }
}
