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
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            Section::make('Fayda identity')
                ->description('Admins may correct these fields. The next Fayda sign-in can overwrite them from National ID. Fayda sub cannot be changed.')
                ->schema([
                    TextInput::make('public_id')
                        ->label('Public ID')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('sub')
                        ->label('Fayda sub')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('phone_number')
                        ->label('Phone')
                        ->tel()
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        ->helperText('Saved as last 9 digits. Must be unique across contacts.')
                        ->dehydrateStateUsing(
                            fn (?string $state): ?string => \App\Support\PhoneNumber::normalizeNullable($state)
                        ),
                    TextInput::make('email')
                        ->email()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Must be unique across contacts when set.')
                        ->dehydrateStateUsing(
                            fn (?string $state): ?string => \App\Support\EmailAddress::normalize($state)
                        ),
                    TextInput::make('gender')->maxLength(64),
                    TextInput::make('nationality')->maxLength(120),
                    TextInput::make('identification_type')->label('ID type')->maxLength(120),
                    TextInput::make('identification_number')->label('ID number')->maxLength(120),
                    DatePicker::make('birthdate')->native(false),
                    Textarea::make('address')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Optional free-text or JSON address from Fayda.')
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
                ])->columns(3),
            Section::make('Status')
                ->schema([
                    Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Inactive contacts cannot use the partner portal.'),
                    Toggle::make('is_banned')
                        ->label('Banned')
                        ->helperText('Banned contacts are blocked from signing in.'),
                    Toggle::make('fayda_verified')
                        ->label('Identity verified via Fayda')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Set only when the partner signs in with Fayda. Admins cannot change this.'),
                    TextInput::make('legacy_mvas_id')
                        ->label('Legacy MVAS ID')
                        ->maxLength(64),
                ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->description('Personal KYC via Fayda (National ID) or Ethio telecom CRM. Sticky once verified — not editable by admin.')
                ->schema([
                    TextEntry::make('public_id'),
                    TextEntry::make('sub')->label('SSO / placeholder sub'),
                    TextEntry::make('identity_verified_via')
                        ->label('Verified via')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => match ((string) $state) {
                            'fayda' => 'Fayda',
                            'crm' => 'CRM',
                            default => 'Unverified',
                        })
                        ->color(fn ($state): string => match ((string) $state) {
                            'fayda', 'crm' => 'success',
                            default => 'warning',
                        }),
                    TextEntry::make('identity_verified_at')->label('Verified at')->dateTime()->placeholder('—'),
                    TextEntry::make('fayda_verified')
                        ->label('Fayda flag')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                        ->color(fn ($state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('name'),
                    TextEntry::make('phone_number'),
                    TextEntry::make('email'),
                    TextEntry::make('gender'),
                    TextEntry::make('nationality'),
                    TextEntry::make('identification_type'),
                    TextEntry::make('identification_number'),
                    TextEntry::make('birthdate')->date(),
                    TextEntry::make('address')->formatStateUsing(
                        fn ($state) => is_array($state) ? json_encode($state) : $state
                    )->columnSpanFull(),
                ])->columns(3),
            Section::make('Current company context')
                ->description('Active portal company for this contact. They may also belong to other companies under Company memberships.')
                ->schema([
                    TextEntry::make('company.name')
                        ->label('Current company')
                        ->placeholder('—')
                        ->url(fn (Contact $record): ?string => $record->company
                            ? CompanyResource::getUrl('view', ['record' => $record->company])
                            : null),
                    TextEntry::make('company.tin')->label('TIN')->placeholder('—'),
                    TextEntry::make('company_role')->label('Role in current company')->placeholder('—'),
                    TextEntry::make('company_phone')->placeholder('—'),
                    TextEntry::make('company_email')->placeholder('—'),
                    TextEntry::make('company_address')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('profile_completed_at')->dateTime()->label('Completed at')->placeholder('—'),
                    TextEntry::make('memberships_count')
                        ->label('Total memberships')
                        ->state(fn (Contact $record): int => $record->memberships()->count()),
                ])->columns(2),
            Section::make('Status')->schema([
                TextEntry::make('is_active')->badge(),
                TextEntry::make('is_banned')->badge(),
                TextEntry::make('fayda_verified')
                    ->label('Fayda verified')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(4),
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
                IconColumn::make('fayda_verified')->boolean()->label('Fayda'),
                TextColumn::make('identity_verified_via')
                    ->label('KYC')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ((string) $state) {
                        'fayda' => 'Fayda',
                        'crm' => 'CRM',
                        default => '—',
                    })
                    ->color(fn ($state): string => match ((string) $state) {
                        'fayda', 'crm' => 'success',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->onColor('success')
                    ->offColor('gray'),
                ToggleColumn::make('is_banned')
                    ->label('Banned')
                    ->onColor('danger')
                    ->offColor('gray'),
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
                TernaryFilter::make('fayda_verified')
                    ->label('Fayda verified')
                    ->boolean()
                    ->placeholder('Any')
                    ->trueLabel('Verified via Fayda')
                    ->falseLabel('Not Fayda-verified'),
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
                TernaryFilter::make('is_banned')
                    ->label('Banned')
                    ->boolean()
                    ->placeholder('Any')
                    ->trueLabel('Banned')
                    ->falseLabel('Not banned'),
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete_safe')
                        ->label('Delete selected (safe)')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected contacts?')
                        ->modalDescription('Soft-deletes orphan contacts only. Contacts with a company, membership, tickets, subscriptions, or change requests are skipped.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => static::canDeleteAny())
                        ->action(function (Collection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            $records->loadMissing([
                                'memberships',
                                'tickets',
                                'subscriptions',
                                'companyChangeRequests',
                            ]);

                            foreach ($records as $contact) {
                                /** @var Contact $contact */
                                if (! $contact->isSafeToSoftDelete()) {
                                    $skipped++;

                                    continue;
                                }

                                $contact->delete();
                                $deleted++;
                            }

                            if ($deleted > 0) {
                                Notification::make()
                                    ->title("Deleted {$deleted} contact(s)")
                                    ->body($skipped > 0 ? "{$skipped} skipped (linked to company or activity)." : null)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('No contacts deleted')
                                    ->body('Selected contacts still have a company link, membership, tickets, subscriptions, or change requests. Filter to “Orphan” first.')
                                    ->warning()
                                    ->send();
                            }
                        }),
                    DeleteBulkAction::make()
                        ->label('Delete selected')
                        ->authorizeIndividualRecords('delete')
                        ->visible(fn (): bool => static::canDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn (): bool => static::canRestoreAny()),
                    ForceDeleteBulkAction::make()
                        ->label('Permanently delete')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => static::canForceDeleteAny()),
                ]),
            ]);
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

    public static function canEdit(Model $record): bool
    {
        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Contact && $record->isSafeToSoftDelete();
    }

    public static function canDeleteAny(): bool
    {
        return true;
    }

    public static function canForceDelete(Model $record): bool
    {
        return $record instanceof Contact && $record->isSafeToSoftDelete();
    }

    public static function canForceDeleteAny(): bool
    {
        return true;
    }

    public static function canRestore(Model $record): bool
    {
        return true;
    }

    public static function canRestoreAny(): bool
    {
        return true;
    }
}
