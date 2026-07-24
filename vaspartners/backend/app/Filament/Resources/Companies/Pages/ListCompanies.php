<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    public function getTabs(): array
    {
        $base = fn (): Builder => Company::query();

        return [
            'all' => Tab::make('All')->badge(fn (): int => $base()->count()),
            'pending' => Tab::make('Pending approval')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Pending)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Pending)),
            'approved' => Tab::make('Approved')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Approved)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Approved)),
            'orphans' => Tab::make('Orphan (no owner)')
                ->badge(fn (): int => $base()->ownerless()->where('approval_status', CompanyApprovalStatus::Approved)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->ownerless()
                    ->where('approval_status', CompanyApprovalStatus::Approved)),
            'rejected' => Tab::make('Rejected')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Rejected)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Rejected)),
        ];
    }
}
