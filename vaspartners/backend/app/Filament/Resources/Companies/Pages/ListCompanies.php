<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

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
        $base = fn (): Builder => Company::query();

        return [
            'all' => Tab::make('All')->badge(fn (): int => $base()->count()),
            'tin_ok' => Tab::make('TIN number verified')
                ->badge(fn (): int => $base()->tinApproved()->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->tinApproved()),
            'tin_awaiting' => Tab::make('Awaiting verification')
                ->badge(fn (): int => $base()->awaitingTinApproval()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingTinApproval()),
            'mismatch' => Tab::make('Name mismatch')
                ->badge(fn (): int => $base()->ercaNameMismatchPending()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->ercaNameMismatchPending()),
            'invalid_tin' => Tab::make('Invalid TIN number')
                ->badge(fn (): int => $base()->invalidOrMissingTin()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->invalidOrMissingTin()),
            'orphans' => Tab::make('Orphan (no owner)')
                ->badge(fn (): int => $base()->ownerless()->ercaIdentityResolved()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->ownerless()->ercaIdentityResolved()),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => $base()->where('is_active', false)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }
}
