<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Live ticket counts per account handler by real ticket status.
 * Grain: current assignee (`assigned_to_user_id`).
 */
class AccountManagerWorkloadService
{
    /**
     * @param  array{service_ids?: list<int>|null, user_id?: ?int}  $filters
     * @return Collection<int, array{
     *   user_id: int,
     *   name: string,
     *   email: string,
     *   open: int,
     *   in_progress: int,
     *   rejected: int,
     *   completed: int,
     *   closed: int,
     *   total: int,
     *   holding: int
     * }>
     */
    public function rows(array $filters = []): Collection
    {
        $serviceIds = $this->resolveServiceIds($filters);
        $userId = isset($filters['user_id']) ? (int) $filters['user_id'] : 0;

        $open = TicketStatus::Open->value;
        $inProgress = TicketStatus::InProgress->value;
        $rejected = TicketStatus::Rejected->value;
        $completed = TicketStatus::Completed->value;
        $closed = TicketStatus::Closed->value;

        $aggregates = Ticket::query()
            ->whereNotNull('assigned_to_user_id')
            ->when($serviceIds !== [], fn ($q) => $q->whereIn('service_id', $serviceIds))
            ->when($userId > 0, fn ($q) => $q->where('assigned_to_user_id', $userId))
            ->select('assigned_to_user_id')
            ->selectRaw('count(*) filter (where status = ?)::int as c_open', [$open])
            ->selectRaw('count(*) filter (where status = ?)::int as c_in_progress', [$inProgress])
            ->selectRaw('count(*) filter (where status = ?)::int as c_rejected', [$rejected])
            ->selectRaw('count(*) filter (where status = ?)::int as c_completed', [$completed])
            ->selectRaw('count(*) filter (where status = ?)::int as c_closed', [$closed])
            ->selectRaw('count(*)::int as c_total')
            ->groupBy('assigned_to_user_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_to_user_id);

        if ($aggregates->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $aggregates->keys()->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return $aggregates
            ->map(function ($row) use ($users) {
                $userId = (int) $row->assigned_to_user_id;
                $user = $users->get($userId);
                $openCount = (int) ($row->c_open ?? 0);
                $inProgressCount = (int) ($row->c_in_progress ?? 0);

                return [
                    'user_id' => $userId,
                    'name' => $user?->name ?? ('User #'.$userId),
                    'email' => (string) ($user?->email ?? ''),
                    'open' => $openCount,
                    'in_progress' => $inProgressCount,
                    'rejected' => (int) ($row->c_rejected ?? 0),
                    'completed' => (int) ($row->c_completed ?? 0),
                    'closed' => (int) ($row->c_closed ?? 0),
                    'total' => (int) ($row->c_total ?? 0),
                    'holding' => $openCount + $inProgressCount,
                ];
            })
            ->sortByDesc('holding')
            ->values();
    }

    /**
     * @param  array{service_ids?: list<int>|null, user_id?: ?int}  $filters
     * @return array{
     *   handlers: int,
     *   open: int,
     *   in_progress: int,
     *   rejected: int,
     *   completed: int,
     *   closed: int,
     *   total: int,
     *   holding: int
     * }
     */
    public function teamSummary(array $filters = []): array
    {
        $rows = $this->rows($filters);

        return [
            'handlers' => $rows->count(),
            'open' => (int) $rows->sum('open'),
            'in_progress' => (int) $rows->sum('in_progress'),
            'rejected' => (int) $rows->sum('rejected'),
            'completed' => (int) $rows->sum('completed'),
            'closed' => (int) $rows->sum('closed'),
            'total' => (int) $rows->sum('total'),
            'holding' => (int) $rows->sum('holding'),
        ];
    }

    /**
     * @param  array{service_ids?: list<int>|null}  $filters
     * @return list<int>
     */
    protected function resolveServiceIds(array $filters): array
    {
        return collect($filters['service_ids'] ?? [])
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
