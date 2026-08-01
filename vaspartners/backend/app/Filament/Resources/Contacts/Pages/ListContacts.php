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
        $base = fn (): Builder => Contact::query();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'with_company' => Tab::make('With company')
                ->badge(fn (): int => $base()->whereNotNull('current_company_id')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('current_company_id')),
            'no_company' => Tab::make('No company')
                ->badge(fn (): int => $base()->whereNull('current_company_id')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('current_company_id')),
            'orphans' => Tab::make('Orphans')
                ->badge(fn (): int => $base()->orphans()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->orphans()),
            'verified' => Tab::make('Identity verified')
                ->badge(fn (): int => $base()->where(function (Builder $q): void {
                    $q->whereNotNull('identity_verified_via')
                        ->orWhere('fayda_verified', true);
                })->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q): void {
                    $q->whereNotNull('identity_verified_via')
                        ->orWhere('fayda_verified', true);
                })),
            'unverified' => Tab::make('Unverified')
                ->badge(fn (): int => $base()->whereNull('identity_verified_via')
                    ->where(function (Builder $q): void {
                        $q->whereNull('fayda_verified')->orWhere('fayda_verified', false);
                    })->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('identity_verified_via')
                    ->where(function (Builder $q): void {
                        $q->whereNull('fayda_verified')->orWhere('fayda_verified', false);
                    })),
            'active' => Tab::make('Active')
                ->badge(fn (): int => $base()->where('is_active', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => $base()->where('is_active', false)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'legacy' => Tab::make('Legacy MVAS')
                ->badge(fn (): int => $base()->whereNotNull('legacy_mvas_id')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('legacy_mvas_id')),
        ];
    }
}
