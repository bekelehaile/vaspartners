<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\TicketsRelationManager;
use App\Models\Contact;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
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
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Fayda identity')
                ->description('Verified by Fayda (National ID). These fields cannot be edited in admin or the partner portal — only Fayda login may refresh them.')
                ->schema([
                    TextEntry::make('public_id'),
                    TextEntry::make('sub')->label('Fayda sub'),
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
            Section::make('Company details')
                ->description('Organisation linked to this Fayda partner (create or attach flow).')
                ->schema([
                    TextEntry::make('company.name')
                        ->label('Company')
                        ->placeholder('—')
                        ->url(fn (Contact $record): ?string => $record->company
                            ? CompanyResource::getUrl('view', ['record' => $record->company])
                            : null),
                    TextEntry::make('company.tin')->label('TIN')->placeholder('—'),
                    TextEntry::make('company.license_number')->label('License')->placeholder('—'),
                    TextEntry::make('company_role')->label('Role')->placeholder('—'),
                    TextEntry::make('company_phone')->placeholder('—'),
                    TextEntry::make('company_email')->placeholder('—'),
                    TextEntry::make('company_address')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('profile_completed_at')->dateTime()->label('Completed at')->placeholder('—'),
                ])->columns(2),
            Section::make('Status')->schema([
                TextEntry::make('is_active')->badge(),
                TextEntry::make('is_banned')->badge(),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—')
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
        return false;
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
