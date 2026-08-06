<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\ServiceOperationalStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\ProvisioningLogsRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
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
                    ->formatStateUsing(fn ($state) => SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => SubscriptionStatus::tryColor($state)),
                TextEntry::make('operational_status')
                    ->label('Uptime status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ServiceOperationalStatus::tryLabel($state))
                    ->color(fn ($state): string => ServiceOperationalStatus::tryColor($state))
                    ->helperText('Staff-reported until an external probe is connected.'),
                TextEntry::make('operational_status_updated_at')
                    ->label('Uptime updated')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('service.name')->label('Service'),
                TextEntry::make('renewal_interval')->badge()->placeholder('—'),
                TextEntry::make('current_period_start')->dateTime()->placeholder('—'),
                TextEntry::make('current_period_end')->dateTime()->placeholder('—'),
                TextEntry::make('next_renewal_due_at')->dateTime()->placeholder('—'),
                TextEntry::make('started_at')->dateTime()->placeholder('—'),
                TextEntry::make('terminated_at')->label('Deactivated at')->dateTime()->placeholder('—'),
                TextEntry::make('closed_at')->label('Closed at')->dateTime()->placeholder('—'),
            ])->columns(2),
            Section::make('Contract follow-up')->schema([
                TextEntry::make('contract_signed_at')
                    ->label('Contract signing date')
                    ->date()
                    ->placeholder('—'),
                TextEntry::make('renewal_date')
                    ->label('Renewal date')
                    ->date()
                    ->placeholder('—'),
                TextEntry::make('vas_license_expires_at')
                    ->label('VAS license expiry')
                    ->date()
                    ->placeholder('—')
                    ->visible(fn (Subscription $record): bool => $record->requiresVasLicenseExpiry()
                        || $record->vas_license_expires_at !== null),
                TextEntry::make('contract_ready')
                    ->label('Ready to close')
                    ->badge()
                    ->state(fn (Subscription $record): string => $record->status === SubscriptionStatus::Closed
                        ? 'Closed'
                        : ($record->isReadyToClose() ? 'Yes' : 'Missing fields'))
                    ->color(fn (Subscription $record): string => $record->status === SubscriptionStatus::Closed
                        ? 'danger'
                        : ($record->isReadyToClose() ? 'success' : 'warning'))
                    ->helperText(fn (Subscription $record): ?string => $record->status === SubscriptionStatus::Closed
                        ? null
                        : (($missing = $record->missingContractCloseFields()) !== []
                            ? 'Still need: '.implode(', ', $missing)
                            : ($record->requiresVasLicenseExpiry()
                                ? 'Premium: contract date, renewal year, and VAS license expiry are set.'
                                : 'Contract date and renewal year are set.'))),
            ])->columns(2),
            Section::make('Company & partner')->schema([
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextEntry::make('company.tin')->label('TIN number')->placeholder('—'),
                IconEntry::make('company_tin_ok')
                    ->label('TIN number status')
                    ->boolean()
                    ->state(fn (Subscription $record): bool => (bool) $record->company?->isTinValidated()),
                TextEntry::make('contact.name')
                    ->label('Activated by')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null),
                TextEntry::make('contact.phone_number')->label('Phone')->placeholder('—'),
            ])->columns(2),
            Section::make('Linked tickets')->schema([
                TextEntry::make('activatedByTicket.tt_number')
                    ->label('Activated by')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->activatedByTicket
                        ? TicketResource::getUrl('view', ['record' => $record->activatedByTicket])
                        : null),
                TextEntry::make('terminatedByTicket.tt_number')
                    ->label('Deactivated by')
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company', 'service', 'contact']))
            ->columns([
                TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—'),
                TextColumn::make('company.tin')
                    ->label('TIN number')
                    ->searchable()
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                IconColumn::make('company_tin_ok')
                    ->label('TIN number status')
                    ->boolean()
                    ->state(fn (Subscription $record): bool => (bool) $record->company?->isTinValidated())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            '(SELECT CASE WHEN companies.erca_tin_verified = true AND companies.tin ~ \'^[0-9]{10}$\' THEN 1 ELSE 0 END FROM companies WHERE companies.id = subscriptions.company_id) '.$direction
                        );
                    }),
                TextColumn::make('service.name')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => SubscriptionStatus::tryColor($state)),
                TextColumn::make('operational_status')
                    ->label('Uptime')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ServiceOperationalStatus::tryLabel($state))
                    ->color(fn ($state): string => ServiceOperationalStatus::tryColor($state))
                    ->toggleable(),
                TextColumn::make('contact.name')->label('Activated by')->searchable()->toggleable(),
                TextColumn::make('renewal_interval')->badge(),
                TextColumn::make('contract_signed_at')
                    ->label('Contract date')
                    ->date()
                    ->toggleable(),
                TextColumn::make('renewal_date')
                    ->label('Renewal date')
                    ->date()
                    ->toggleable(),
                TextColumn::make('current_period_end')->dateTime()->sortable()->toggleable(),
                TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(SubscriptionStatus::options()),
                SelectFilter::make('service_id')
                    ->label('Service')
                    ->relationship('service', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('contract_follow_up')
                    ->label('Contract follow-up')
                    ->options([
                        'missing' => 'Missing contract fields',
                        'ready' => 'Ready to close',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'missing' => $query
                                ->whereNull('closed_at')
                                ->where(function (Builder $q): void {
                                    $q->whereNull('contract_signed_at')
                                        ->orWhereNull('renewal_date')
                                        ->orWhere(function (Builder $premium): void {
                                            $premium->whereHas('service', function (Builder $s): void {
                                                $s->where(function (Builder $inner): void {
                                                    $inner->where('slug', 'like', '%premium%')
                                                        ->where('slug', 'not like', '%non-premium%')
                                                        ->where('slug', 'not like', '%non_premium%');
                                                })->orWhere(function (Builder $inner): void {
                                                    $inner->where('name', 'ilike', '%premium%')
                                                        ->where('name', 'not ilike', '%non-premium%')
                                                        ->where('name', 'not ilike', '%non premium%');
                                                });
                                            })->whereNull('vas_license_expires_at');
                                        });
                                }),
                            'ready' => $query
                                ->whereNotNull('contract_signed_at')
                                ->whereNotNull('renewal_date')
                                ->where(function (Builder $q): void {
                                    $q->whereDoesntHave('service', function (Builder $s): void {
                                        $s->where(function (Builder $inner): void {
                                            $inner->where('slug', 'like', '%premium%')
                                                ->where('slug', 'not like', '%non-premium%')
                                                ->where('slug', 'not like', '%non_premium%');
                                        });
                                    })->orWhereNotNull('vas_license_expires_at');
                                }),
                            default => $query,
                        };
                    }),
                SelectFilter::make('tin_status')
                    ->label('TIN number status')
                    ->options([
                        'verified' => 'Verified',
                        'unverified' => 'Not verified',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'verified' => $query->whereHas('company', fn (Builder $q) => $q
                                ->where('erca_tin_verified', true)
                                ->whereRaw("tin ~ '^[0-9]{10}$'")),
                            'unverified' => $query->where(function (Builder $q): void {
                                $q->whereDoesntHave('company')
                                    ->orWhereHas('company', fn (Builder $inner) => $inner
                                        ->where(function (Builder $c): void {
                                            $c->where('erca_tin_verified', false)
                                                ->orWhereNull('erca_tin_verified')
                                                ->orWhereRaw("tin !~ '^[0-9]{10}$'");
                                        }));
                            }),
                            default => $query,
                        };
                    }),
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
            ProvisioningLogsRelationManager::class,
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
