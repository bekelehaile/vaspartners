<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use STS\FilamentImpersonate\Actions\Impersonate;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('username')
                ->label('Username')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(64),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\EmailAddress::normalize($state)),
            TextInput::make('phone')
                ->tel()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(32)
                ->placeholder('0912345678')
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\PhoneNumber::normalizeNullable($state)),
            Select::make('roles')
                ->label('Roles')
                ->relationship(
                    name: 'roles',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query
                        ->where('name', '!=', 'super_admin')
                        ->orderBy('name'),
                )
                ->multiple()
                ->preload()
                ->searchable()
                ->required()
                ->columnSpanFull(),
            Select::make('manager_id')
                ->label('Reports to')
                ->relationship(
                    name: 'manager',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                )
                ->searchable()
                ->preload()
                ->nullable(),
            Select::make('categories')
                ->label('Group scope')
                ->relationship(
                    name: 'categories',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query
                        ->whereIn('key', [\App\Models\Category::KEY_GROUP_1, \App\Models\Category::KEY_GROUP_2])
                        ->orderBy('sort_order'),
                )
                ->multiple()
                ->preload()
                ->searchable()
                ->columnSpanFull(),
            Toggle::make('is_management')
                ->label('Can close tickets / receive new-ticket alerts')
                ->default(false),
            Toggle::make('must_change_password')
                ->label('Must change password on next login')
                ->default(true),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('username')->searchable()->sortable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(','),
                TextColumn::make('manager.name')->label('Reports to')->toggleable(),
                IconColumn::make('is_management')->label('Alerts')->boolean()->toggleable(),
                IconColumn::make('must_change_password')->label('Temp PW')->boolean()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->dateTimeTooltip()
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Impersonate::make()
                    ->redirectTo(filament()->getCurrentOrDefaultPanel()?->getUrl() ?? '/admin')
                    ->backTo(filament()->getCurrentOrDefaultPanel()?->getUrl() ?? '/admin'),
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
