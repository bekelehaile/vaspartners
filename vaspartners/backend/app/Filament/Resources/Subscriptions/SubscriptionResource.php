<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $recordTitleAttribute = 'public_id';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof Subscription) {
            return parent::getRecordTitle($record);
        }

        $serviceName = $record->service?->name;

        if (filled($serviceName)) {
            return (string) $serviceName;
        }

        return $record->public_id ?: parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')->schema([
                TextEntry::make('public_id')->label('Subscription ID'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus
                        ? $state->label()
                        : (SubscriptionStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => match ($state instanceof SubscriptionStatus ? $state : SubscriptionStatus::tryFrom((string) $state)) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::PendingRenewal, SubscriptionStatus::Grace => 'warning',
                        SubscriptionStatus::Expired, SubscriptionStatus::Terminated => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('service.name')->label('Service'),
                TextEntry::make('renewal_interval')->badge()->placeholder('—'),
                TextEntry::make('current_period_start')->dateTime()->placeholder('—'),
                TextEntry::make('current_period_end')->dateTime()->placeholder('—'),
                TextEntry::make('next_renewal_due_at')->dateTime()->placeholder('—'),
                TextEntry::make('started_at')->dateTime()->placeholder('—'),
                TextEntry::make('terminated_at')->dateTime()->placeholder('—'),
            ])->columns(2),
            Section::make('Company & partner')->schema([
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextEntry::make('company.tin')->label('TIN')->placeholder('—'),
                TextEntry::make('customer.name')
                    ->label('Activated by')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->customer
                        ? CustomerResource::getUrl('view', ['record' => $record->customer])
                        : null),
                TextEntry::make('customer.phone_number')->label('Phone')->placeholder('—'),
            ])->columns(2),
            Section::make('Linked tickets')->schema([
                TextEntry::make('activatedByTicket.tt_number')
                    ->label('Activated by')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->activatedByTicket
                        ? TicketResource::getUrl('view', ['record' => $record->activatedByTicket])
                        : null),
                TextEntry::make('terminatedByTicket.tt_number')
                    ->label('Terminated by')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->terminatedByTicket
                        ? TicketResource::getUrl('view', ['record' => $record->terminatedByTicket])
                        : null),
                TextEntry::make('tickets_count')
                    ->label('Total requests')
                    ->state(fn (Subscription $record): int => $record->tickets()->count()),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—'),
            TextColumn::make('service.name')->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('customer.name')->label('Activated by')->searchable()->toggleable(),
            TextColumn::make('renewal_interval')->badge(),
            TextColumn::make('current_period_end')->dateTime()->sortable()->toggleable(),
            TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
        ])->filters([
            SelectFilter::make('status')->options(SubscriptionStatus::options()),
            SelectFilter::make('service_id')
                ->label('Service')
                ->relationship('service', 'name')
                ->searchable()
                ->preload(),
        ])->recordActions([
            ViewAction::make()
                ->url(fn (Subscription $record): string => static::getUrl('view', ['record' => $record])),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            TicketsRelationManager::class,
            MessagesRelationManager::class,
            DocumentsRelationManager::class,
            StatusHistoryRelationManager::class,
        ];
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
