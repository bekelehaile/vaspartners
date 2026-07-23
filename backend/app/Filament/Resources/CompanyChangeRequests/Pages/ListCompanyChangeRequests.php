<?php

namespace App\Filament\Resources\CompanyChangeRequests\Pages;

use App\Enums\CompanyChangeStatus;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanyChangeRequests extends ListRecords
{
    protected static string $resource = CompanyChangeRequestResource::class;

    public function getTabs(): array
    {
        $base = fn (): Builder => CompanyChangeRequest::query();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'pending' => Tab::make('Pending')
                ->badge(fn (): int => $base()->where('status', CompanyChangeStatus::Pending)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CompanyChangeStatus::Pending)),
            'approved' => Tab::make('Approved')
                ->badge(fn (): int => $base()->where('status', CompanyChangeStatus::Approved)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CompanyChangeStatus::Approved)),
            'rejected' => Tab::make('Rejected')
                ->badge(fn (): int => $base()->where('status', CompanyChangeStatus::Rejected)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CompanyChangeStatus::Rejected)),
        ];
    }
}
