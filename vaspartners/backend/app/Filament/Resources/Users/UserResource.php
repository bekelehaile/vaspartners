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
use Spatie\Permission\Models\Role;
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
                ->maxLength(64)
                ->helperText('Shown in SMS with temporary credentials on create. Sign-in uses phone number only.'),
            TextInput::make('email')
                ->label('Email address')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('phone')
                ->tel()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(32)
                ->placeholder('e.g. 0912345678')
                ->helperText('Used to sign in to admin.'),
            Select::make('roles')
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
                ->default(fn (): array => array_filter([
                    Role::findOrCreate('account_manager', 'web')->getKey(),
                ]))
                ->helperText('Default is account_manager (My Tickets). Super admin cannot be assigned here.')
                ->columnSpanFull(),
            Select::make('manager_id')
                ->label('Manager')
                ->relationship(
                    name: 'manager',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                )
                ->searchable()
                ->preload()
                ->nullable(),
            Select::make('categories')
                ->label('Category scope')
                ->relationship('categories', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->columnSpanFull(),
            Toggle::make('is_management')
                ->label('Management / supervisor')
                ->helperText('Receives new ticket alerts and can close tickets.'),
            Toggle::make('must_change_password')
                ->label('Must change password')
                ->default(true)
                ->helperText('When enabled, the user must set a new password on next login.'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Inactive users cannot sign in to the admin panel.'),
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
                TextColumn::make('manager.name')->label('Manager')->toggleable(),
                IconColumn::make('is_management')->label('Mgmt')->boolean(),
                IconColumn::make('must_change_password')->label('Temp PW')->boolean()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
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
