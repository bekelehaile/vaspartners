<?php

namespace App\Filament\Resources\Users;

use App\Enums\ProvisioningDepartment;
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
use Illuminate\Support\Collection;
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
                        ->whereNotIn('name', static::departmentRoleNames())
                        ->orderBy('name'),
                )
                ->multiple()
                ->preload()
                ->searchable()
                ->required()
                ->helperText('Job roles such as account manager or supervisor.')
                ->columnSpanFull(),
            Select::make('departments')
                ->label('Belongs to departments')
                ->options(static::departmentOptions())
                ->multiple()
                ->searchable()
                ->preload()
                ->nullable()
                ->dehydrated(false)
                ->placeholder('Optional — e.g. VAS Operation, IS Billing, IS Security')
                ->helperText('Optional for third-party / section staff (VAS Operation, MA, IS Billing, IS Security; also VAS Sales if needed). A user may belong to many. Account managers do not need this — when a case is assigned to them they already act as VAS Sales.')
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
                TextColumn::make('job_roles')
                    ->label('Roles')
                    ->badge()
                    ->state(fn (User $record): array => $record->getRoleNames()
                        ->reject(fn (string $name): bool => in_array($name, static::departmentRoleNames(), true)
                            || $name === 'super_admin')
                        ->values()
                        ->all())
                    ->placeholder('—'),
                TextColumn::make('departments')
                    ->label('Departments')
                    ->badge()
                    ->state(fn (User $record): array => collect($record->provisioningDepartments())
                        ->map(fn (string $value): string => ProvisioningDepartment::tryFrom($value)?->label() ?? $value)
                        ->all())
                    ->placeholder('—'),
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

    /**
     * @return list<string>
     */
    public static function departmentRoleNames(): array
    {
        return collect(ProvisioningDepartment::cases())
            ->reject(fn (ProvisioningDepartment $d) => $d === ProvisioningDepartment::Partner)
            ->map(fn (ProvisioningDepartment $d) => $d->value)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function departmentOptions(): array
    {
        return collect(ProvisioningDepartment::cases())
            ->reject(fn (ProvisioningDepartment $d) => $d === ProvisioningDepartment::Partner)
            ->mapWithKeys(fn (ProvisioningDepartment $d) => [$d->value => $d->label()])
            ->all();
    }

    /**
     * Sync optional multi-department Spatie roles without touching other roles.
     *
     * @param  list<string>|Collection<int, string>|null  $departments
     */
    public static function syncDepartments(User $user, array|Collection|null $departments): void
    {
        $selected = collect($departments ?? [])
            ->map(fn ($v) => (string) $v)
            ->filter(fn (string $v) => in_array($v, static::departmentRoleNames(), true))
            ->unique()
            ->values();

        foreach (static::departmentRoleNames() as $roleName) {
            Role::findOrCreate($roleName, 'web');
            if ($selected->contains($roleName)) {
                if (! $user->hasRole($roleName)) {
                    $user->assignRole($roleName);
                }
            } elseif ($user->hasRole($roleName)) {
                $user->removeRole($roleName);
            }
        }
    }
}
