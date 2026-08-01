<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\ErcaNameStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    /**
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

    public function getTitle(): string
    {
        return 'Companies';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $counts = fn (): array => $this->tabCounts();

        return [
            'all' => Tab::make('All')->badge(fn (): int => $counts()['all']),
            'tin_ok' => Tab::make('TIN number verified')
                ->badge(fn (): int => $counts()['tin_ok'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->tinApproved()),
            'tin_awaiting' => Tab::make('Awaiting verification')
                ->badge(fn (): int => $counts()['tin_awaiting'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingTinApproval()),
            'mismatch' => Tab::make('Name mismatch')
                ->badge(fn (): int => $counts()['mismatch'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->ercaNameMismatchPending()),
            'invalid_tin' => Tab::make('Invalid TIN number')
                ->badge(fn (): int => $counts()['invalid_tin'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->invalidOrMissingTin()),
            'orphans' => Tab::make('Orphan (no owner)')
                ->badge(fn (): int => $counts()['orphans'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->ownerless()->ercaIdentityResolved()),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => $counts()['inactive'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function tabCounts(): array
    {
        if ($this->tabCounts !== null) {
            return $this->tabCounts;
        }

        $tinDigits = "length(regexp_replace(coalesce(tin, ''), '[^0-9]', '', 'g'))";
        $done = [
            ErcaNameStatus::Matched->value,
            ErcaNameStatus::AcceptedLegal->value,
            ErcaNameStatus::KeptBoth->value,
            ErcaNameStatus::MismatchPending->value,
            ErcaNameStatus::NameMissing->value,
            ErcaNameStatus::PartnerEntered->value,
        ];
        $doneList = "'".implode("','", $done)."'";
        $mismatch = ErcaNameStatus::MismatchPending->value;

        $row = Company::query()
            ->toBase()
            ->selectRaw(
                "count(*)::int as c_all,
                count(*) filter (
                    where erca_tin_verified = true
                      and tin is not null and tin <> ''
                      and {$tinDigits} = 10
                )::int as c_tin_ok,
                count(*) filter (
                    where tin is not null and tin <> ''
                      and {$tinDigits} = 10
                      and (
                        erca_tin_verified = false
                        or erca_name_status is null
                        or erca_name_status not in ({$doneList})
                      )
                )::int as c_tin_awaiting,
                count(*) filter (where erca_name_status = ?)::int as c_mismatch,
                count(*) filter (
                    where tin is null or tin = '' or {$tinDigits} <> 10
                )::int as c_invalid_tin,
                count(*) filter (where is_active = false)::int as c_inactive",
                [$mismatch],
            )
            ->first();

        $orphans = Company::query()
            ->ownerless()
            ->ercaIdentityResolved()
            ->count();

        return $this->tabCounts = [
            'all' => (int) ($row->c_all ?? 0),
            'tin_ok' => (int) ($row->c_tin_ok ?? 0),
            'tin_awaiting' => (int) ($row->c_tin_awaiting ?? 0),
            'mismatch' => (int) ($row->c_mismatch ?? 0),
            'invalid_tin' => (int) ($row->c_invalid_tin ?? 0),
            'orphans' => $orphans,
            'inactive' => (int) ($row->c_inactive ?? 0),
        ];
    }
}
