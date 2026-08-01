<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    /**
     * @var array<string, int>|null
     */
    protected ?array $tabCounts = null;

    public function getTitle(): string
    {
        return 'Contacts';
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    public function getTabs(): array
    {
        $counts = fn (): array => $this->tabCounts();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $counts()['all']),
            'with_company' => Tab::make('With company')
                ->badge(fn (): int => $counts()['with_company'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('current_company_id')),
            'no_company' => Tab::make('No company')
                ->badge(fn (): int => $counts()['no_company'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('current_company_id')),
            'orphans' => Tab::make('Orphans')
                ->badge(fn (): int => $counts()['orphans'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->orphans()),
            'verified' => Tab::make('Identity verified')
                ->badge(fn (): int => $counts()['verified'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q): void {
                    $q->whereNotNull('identity_verified_via')
                        ->orWhere('fayda_verified', true);
                })),
            'unverified' => Tab::make('Unverified')
                ->badge(fn (): int => $counts()['unverified'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('identity_verified_via')
                    ->where(function (Builder $q): void {
                        $q->whereNull('fayda_verified')->orWhere('fayda_verified', false);
                    })),
            'active' => Tab::make('Active')
                ->badge(fn (): int => $counts()['active'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => $counts()['inactive'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'legacy' => Tab::make('Legacy MVAS')
                ->badge(fn (): int => $counts()['legacy'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('legacy_mvas_id')),
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

        $row = Contact::query()
            ->toBase()
            ->selectRaw(
                "count(*)::int as c_all,
                count(*) filter (where current_company_id is not null)::int as c_with_company,
                count(*) filter (where current_company_id is null)::int as c_no_company,
                count(*) filter (
                    where identity_verified_via is not null or fayda_verified = true
                )::int as c_verified,
                count(*) filter (
                    where identity_verified_via is null
                      and (fayda_verified is null or fayda_verified = false)
                )::int as c_unverified,
                count(*) filter (where is_active = true)::int as c_active,
                count(*) filter (where is_active = false)::int as c_inactive,
                count(*) filter (where legacy_mvas_id is not null)::int as c_legacy",
            )
            ->first();

        return $this->tabCounts = [
            'all' => (int) ($row->c_all ?? 0),
            'with_company' => (int) ($row->c_with_company ?? 0),
            'no_company' => (int) ($row->c_no_company ?? 0),
            'orphans' => Contact::query()->orphans()->count(),
            'verified' => (int) ($row->c_verified ?? 0),
            'unverified' => (int) ($row->c_unverified ?? 0),
            'active' => (int) ($row->c_active ?? 0),
            'inactive' => (int) ($row->c_inactive ?? 0),
            'legacy' => (int) ($row->c_legacy ?? 0),
        ];
    }
}
