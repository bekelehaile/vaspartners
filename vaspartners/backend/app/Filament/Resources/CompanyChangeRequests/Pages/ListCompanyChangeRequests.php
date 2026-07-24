<?php

namespace App\Filament\Resources\CompanyChangeRequests\Pages;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanyChangeRequests extends ListRecords
{
    protected static string $resource = CompanyChangeRequestResource::class;

    public function getTitle(): string
    {
        return 'Partner requests';
    }

    public function getSubheading(): ?string
    {
        return 'Ownership transfers need admin approval. Membership joins are decided by the company owner in the partner portal.';
    }

    public function getTabs(): array
    {
        $base = fn (): Builder => CompanyChangeRequest::query();

        return [
            'ownership' => Tab::make('Ownership transfers')
                ->badge(fn (): int => $base()
                    ->where('type', CompanyChangeType::TransferOwnership)
                    ->where('status', CompanyChangeStatus::Pending)
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('type', CompanyChangeType::TransferOwnership)),
            'membership' => Tab::make('Membership joins')
                ->badge(fn (): int => $base()
                    ->where('type', CompanyChangeType::Attach)
                    ->where('status', CompanyChangeStatus::Pending)
                    ->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('type', CompanyChangeType::Attach)),
            'leave' => Tab::make('Leave company')
                ->badge(fn (): int => $base()
                    ->where('type', CompanyChangeType::Detach)
                    ->where('status', CompanyChangeStatus::Pending)
                    ->count())
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('type', CompanyChangeType::Detach)),
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'pending' => Tab::make('All pending')
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
