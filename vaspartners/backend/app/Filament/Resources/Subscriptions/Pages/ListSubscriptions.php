<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        $counts = fn (): array => $this->tabCounts();

        return [
            'active' => Tab::make('Active')
                ->badge(fn (): int => $counts()['active'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Active)),
            'pending_renewal' => Tab::make('Pending renewal')
                ->badge(fn (): int => $counts()['pending_renewal'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::PendingRenewal)),
            'grace' => Tab::make('Grace')
                ->badge(fn (): int => $counts()['grace'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Grace)),
            'expired' => Tab::make('Expired')
                ->badge(fn (): int => $counts()['expired'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Expired)),
            'closed' => Tab::make('Closed')
                ->badge(fn (): int => $counts()['closed'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Closed)),
            'deactive' => Tab::make('Deactive')
                ->badge(fn (): int => $counts()['deactive'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubscriptionStatus::Deactive)),
            'all' => Tab::make('All')
                ->badge(fn (): int => $counts()['all']),
        ];
    }

    /**
     * @return array{
     *   active: int,
     *   pending_renewal: int,
     *   grace: int,
     *   expired: int,
     *   closed: int,
     *   deactive: int,
     *   all: int
     * }
     */
    protected function tabCounts(): array
    {
        if ($this->tabCounts !== null) {
            return $this->tabCounts;
        }

        $active = SubscriptionStatus::Active->value;
        $pending = SubscriptionStatus::PendingRenewal->value;
        $grace = SubscriptionStatus::Grace->value;
        $expired = SubscriptionStatus::Expired->value;
        $closed = SubscriptionStatus::Closed->value;
        $deactive = SubscriptionStatus::Deactive->value;

        $row = SubscriptionResource::getEloquentQuery()
            ->toBase()
            ->selectRaw(
                'count(*)::int as c_all,
                count(*) filter (where status = ?)::int as c_active,
                count(*) filter (where status = ?)::int as c_pending,
                count(*) filter (where status = ?)::int as c_grace,
                count(*) filter (where status = ?)::int as c_expired,
                count(*) filter (where status = ?)::int as c_closed,
                count(*) filter (where status = ?)::int as c_deactive',
                [$active, $pending, $grace, $expired, $closed, $deactive],
            )
            ->first();

        return $this->tabCounts = [
            'all' => (int) ($row->c_all ?? 0),
            'active' => (int) ($row->c_active ?? 0),
            'pending_renewal' => (int) ($row->c_pending ?? 0),
            'grace' => (int) ($row->c_grace ?? 0),
            'expired' => (int) ($row->c_expired ?? 0),
            'closed' => (int) ($row->c_closed ?? 0),
            'deactive' => (int) ($row->c_deactive ?? 0),
        ];
    }
}
