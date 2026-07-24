<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\TicketsRelationManager;
use App\Models\Contact;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                IconColumn::make('profile_completed')->boolean()->label('Company OK'),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('is_banned')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
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
        return false;
    }
}
