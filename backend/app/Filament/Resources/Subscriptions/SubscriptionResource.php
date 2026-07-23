<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $recordTitleAttribute = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('public_id'),
            TextEntry::make('company.name')->label('Company')->placeholder('—'),
            TextEntry::make('company.tin')->label('TIN')->placeholder('—'),
            TextEntry::make('customer.name')->label('Activated by'),
            TextEntry::make('customer.phone_number')->label('Phone'),
            TextEntry::make('service.name'),
            TextEntry::make('status')->badge(),
            TextEntry::make('renewal_interval')->badge(),
            TextEntry::make('current_period_start')->dateTime(),
            TextEntry::make('current_period_end')->dateTime(),
            TextEntry::make('next_renewal_due_at')->dateTime(),
            TextEntry::make('activatedByTicket.tt_number')->label('Activated by ticket'),
            TextEntry::make('terminatedByTicket.tt_number')->label('Terminated by'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('ID')->searchable(),
            TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—'),
            TextColumn::make('customer.name')->label('Activated by')->searchable()->toggleable(),
            TextColumn::make('service.name')->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('renewal_interval')->badge(),
            TextColumn::make('current_period_end')->dateTime()->sortable(),
            TextColumn::make('next_renewal_due_at')->dateTime(),
        ])->filters([
            SelectFilter::make('status')->options(SubscriptionStatus::options()),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
