<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\TicketsRelationManager;
use App\Models\Contact;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Contact';

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?string $pluralModelLabel = 'Contacts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('phone_number')
                ->label('Phone')
                ->tel()
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(
                    fn (?string $state): ?string => \App\Support\PhoneNumber::normalizeNullable($state)
                ),
            TextInput::make('email')
                ->email()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(
                    fn (?string $state): ?string => \App\Support\EmailAddress::normalize($state)
                ),
            TextInput::make('gender')->maxLength(64),
            TextInput::make('nationality')->maxLength(120),
            TextInput::make('identification_type')->label('ID type')->maxLength(120),
            TextInput::make('identification_number')->label('ID number')->maxLength(120),
            DatePicker::make('birthdate')->native(false),
            Toggle::make('is_active')->label('Active'),
            Textarea::make('address')
                ->rows(2)
                ->columnSpanFull()
                ->formatStateUsing(function ($state): ?string {
                    if ($state === null || $state === '') {
                        return null;
                    }

                    return is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $state;
                })
                ->dehydrateStateUsing(function (?string $state): mixed {
                    $state = trim((string) $state);
                    if ($state === '') {
                        return null;
                    }
                    $decoded = json_decode($state, true);

                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
                }),
        ])->columns(3);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('phone_number')->label('Phone'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('identity_verified_via')
                        ->label('Identity')
                        ->badge()
                        ->state(fn (Contact $record): ?string => $record->identityVerifiedViaValue())
                        ->formatStateUsing(fn ($state): string => filled($state)
                            ? \App\Support\IdentityLabels::via((string) $state)
                            : 'Unverified')
                        ->color(fn ($state): string => filled($state) ? 'success' : 'warning'),
                    TextEntry::make('is_active')
                        ->label('Active')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                        ->color(fn ($state) => $state ? 'success' : 'danger'),
                    TextEntry::make('identification_number')->label('ID number')->placeholder('—'),
                    TextEntry::make('identification_type')->label('ID type')->placeholder('—'),
                    TextEntry::make('birthdate')->date()->placeholder('—'),
                    TextEntry::make('gender')->placeholder('—'),
                    TextEntry::make('nationality')->placeholder('—'),
                    TextEntry::make('company.name')
                        ->label('Company')
                        ->placeholder('—')
                        ->url(fn (Contact $record): ?string => $record->company
                            ? CompanyResource::getUrl('view', ['record' => $record->company])
                            : null),
                    TextEntry::make('company_role')->label('Role')->placeholder('—'),
                    TextEntry::make('address')
                        ->formatStateUsing(fn ($state) => is_array($state)
                            ? collect($state)->filter()->implode(', ')
                            : $state)
                        ->columnSpanFull()
                        ->placeholder('—'),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Current company')->searchable()->placeholder('—')
                    ->url(fn (Contact $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextColumn::make('phone_number')->searchable(),
                TextColumn::make('email')->toggleable(),
                TextColumn::make('memberships_count')
                    ->counts('memberships')
                    ->label('Memberships')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tickets_count')
                    ->counts('tickets')
                    ->label('Tickets')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('subscriptions_count')
                    ->counts('subscriptions')
                    ->label('Subs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('profile_completed')->boolean()->label('Company OK'),
                TextColumn::make('identity_verified_via')
                    ->label('Verified via')
                    ->badge()
                    ->state(fn (Contact $record): ?string => $record->identityVerifiedViaValue())
                    ->formatStateUsing(fn ($state): string => filled($state)
                        ? \App\Support\IdentityLabels::via((string) $state)
                        : 'Unverified')
                    ->color(fn ($state): string => filled($state) ? 'success' : 'gray'),
                TextColumn::make('identity_verified_at')
                    ->label('Verified at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->onColor('success')
                    ->offColor('gray')
                    ->disabled(fn (): bool => ! static::canEditContacts())
                    ->updateStateUsing(function (Contact $record, mixed $state): void {
                        if (! static::canEditContacts()) {
                            return;
                        }
                        $record->updateFromAdmin(['is_active' => (bool) $state]);
                    }),
                TextColumn::make('legacy_mvas_id')
                    ->label('Legacy ID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('relevance')
                    ->label('Relevance')
                    ->options([
                        'orphan' => 'Orphan (no membership / tickets / subscriptions)',
                        'no_company' => 'No current company',
                        'has_company' => 'Has current company',
                        'with_activity' => 'Has membership, tickets, or subscriptions',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'orphan' => $query
                                ->whereNull('current_company_id')
                                ->whereDoesntHave('memberships')
                                ->whereDoesntHave('tickets')
                                ->whereDoesntHave('subscriptions')
                                ->whereDoesntHave('companyChangeRequests'),
                            'no_company' => $query->whereNull('current_company_id'),
                            'has_company' => $query->whereNotNull('current_company_id'),
                            'with_activity' => $query->where(function (Builder $q): void {
                                $q->whereNotNull('current_company_id')
                                    ->orWhereHas('memberships')
                                    ->orWhereHas('tickets')
                                    ->orWhereHas('subscriptions')
                                    ->orWhereHas('companyChangeRequests');
                            }),
                            default => $query,
                        };
                    }),
                SelectFilter::make('identity_verified_via')
                    ->label('Verified via')
                    ->options([
                        'fayda' => 'Fayda',
                        'crm' => 'CRM',
                        '__none' => 'Unverified',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        if ($value === '__none') {
                            return $query
                                ->whereNull('identity_verified_via')
                                ->where(function (Builder $q): void {
                                    $q->whereNull('fayda_verified')->orWhere('fayda_verified', false);
                                });
                        }

                        return $query->where('identity_verified_via', $value);
                    }),
                TernaryFilter::make('profile_completed_at')
                    ->label('Profile completed')
                    ->nullable()
                    ->placeholder('Any')
                    ->trueLabel('Completed')
                    ->falseLabel('Incomplete'),
                TernaryFilter::make('legacy_mvas_id')
                    ->label('Legacy MVAS')
                    ->nullable()
                    ->placeholder('Any')
                    ->trueLabel('Legacy import')
                    ->falseLabel('Not legacy'),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->placeholder('Any')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                TernaryFilter::make('has_membership')
                    ->label('Has membership')
                    ->placeholder('Any')
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('memberships'),
                        false: fn (Builder $query) => $query->whereDoesntHave('memberships'),
                    ),
                TernaryFilter::make('has_tickets')
                    ->label('Has tickets')
                    ->placeholder('Any')
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('tickets'),
                        false: fn (Builder $query) => $query->whereDoesntHave('tickets'),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Contact $record): bool => static::canEdit($record)),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
            TicketsRelationManager::class,
            SubscriptionsRelationManager::class,
            ServicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEditContacts(): bool
    {
        $user = auth()->user();

        return (bool) ($user && method_exists($user, 'hasRole') && $user->hasRole('super_admin'));
    }

    public static function canEdit(Model $record): bool
    {
        return static::canEditContacts();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
