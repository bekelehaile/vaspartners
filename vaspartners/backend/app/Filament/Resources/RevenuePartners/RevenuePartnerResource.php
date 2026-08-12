<?php

namespace App\Filament\Resources\RevenuePartners;

use App\Filament\Exports\RevenuePartnerExporter;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\RevenuePartners\Pages\CreateRevenuePartner;
use App\Filament\Resources\RevenuePartners\Pages\EditRevenuePartner;
use App\Filament\Resources\RevenuePartners\Pages\ListRevenuePartners;
use App\Filament\Resources\RevenuePartners\Pages\ViewRevenuePartner;
use App\Filament\Resources\RevenuePartners\RelationManagers\MonthlyRevenueRelationManager;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\RevenueCatalogServices;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RevenuePartnerResource extends Resource
{
    protected static ?string $model = RevenuePartner::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Revenue partners';

    protected static ?string $modelLabel = 'Revenue partner';

    protected static ?string $pluralModelLabel = 'Revenue partners';

    protected static ?string $slug = 'revenue-partners';

    protected static ?string $recordTitleAttribute = 'partner_name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Partner')
                ->schema([
                    TextInput::make('partner_name')
                        ->label('Partner name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Name from the finance / billing system (Excel). Not overwritten by company.')
                        ->columnSpanFull(),
                    Select::make('company_id')
                        ->label('Company')
                        ->options(
                            fn (): array => Company::query()
                                ->where('is_active', true)
                                ->where('erca_tin_verified', true)
                                ->orderBy('name')
                                ->get(['id', 'name', 'phone', 'claim_phone', 'revenue_phone', 'tin'])
                                ->mapWithKeys(function (Company $company): array {
                                    $revenuePhone = PhoneNumber::normalizeNullable($company->revenuePhone());
                                    $bits = array_filter([
                                        $company->name,
                                        filled($revenuePhone) ? 'rev '.$revenuePhone : null,
                                        filled($company->tin) ? 'TIN '.$company->tin : null,
                                    ]);

                                    return [$company->id => implode(' · ', $bits)];
                                })
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                            if (filled($get('phone'))) {
                                return;
                            }
                            $company = $state ? Company::query()->find($state) : null;
                            $set(
                                'phone',
                                $company
                                    ? PhoneNumber::normalizeNullable($company->revenuePhone())
                                    : null,
                            );
                        })
                        ->helperText('Optional. Selecting a company defaults the phone to that company’s revenue phone.')
                        ->columnSpanFull(),
                    TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->required()
                        ->maxLength(32)
                        ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state))
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value === null || $value === '') {
                                $fail('Phone is required.');

                                return;
                            }
                            if (! PhoneNumber::isValidLocalMobile($value)) {
                                $fail('Phone must be a local mobile (9/7 + 8 digits).');
                            }
                        })
                        ->helperText('Defaults to the selected company’s revenue phone. Editable.'),
                    Select::make('created_by_user_id')
                        ->label('Account manager')
                        ->options(fn (): array => static::accountManagerOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->default(fn (): ?int => auth()->id())
                        ->disabled(fn (): bool => ! static::viewerCanAccessAllRevenue())
                        ->dehydrated()
                        ->helperText(fn (): string => static::viewerCanAccessAllRevenue()
                            ? 'Staff user who owns this partner.'
                            : 'Assigned to you. Only managers can reassign ownership.')
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(2),

            Section::make('Service & billing')
                ->description(fn (): string => static::viewerIsAdmin()
                    ? ''
                    : 'Editable by admin / super admin only.')
                ->schema([
                    Select::make('vas_service_id')
                        ->label('Catalog service')
                        ->options(fn (): array => RevenueCatalogServices::options())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->helperText('For labeling / SMS wording only. Partners belong to the importing account manager.')
                        ->columnSpanFull(),
                    TextInput::make('service_id')
                        ->label('Service ID')
                        ->maxLength(64)
                        ->nullable()
                        ->unique(ignoreRecord: true)
                        ->requiredWithout('short_code')
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled(trim((string) $state)) ? trim((string) $state) : null)
                        ->helperText('Finance endpoint ID. Unique. Provide Service ID and/or Short code.'),
                    TextInput::make('product_id')
                        ->label('Product ID')
                        ->maxLength(64)
                        ->nullable()
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled(trim((string) $state)) ? trim((string) $state) : null),
                    TextInput::make('spid')
                        ->label('SPID')
                        ->maxLength(64)
                        ->nullable()
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled(trim((string) $state)) ? trim((string) $state) : null),
                    TextInput::make('short_code')
                        ->label('Short code')
                        ->maxLength(64)
                        ->nullable()
                        ->unique(ignoreRecord: true)
                        ->requiredWithout('service_id')
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled(trim((string) $state)) ? trim((string) $state) : null)
                        ->helperText('Provide Service ID and/or Short code. Both may be set together.'),
                    Textarea::make('notes')
                        ->rows(3)
                        ->disabled(fn (): bool => ! static::viewerIsAdmin())
                        ->dehydrated()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Partner')->schema([
                TextEntry::make('partner_name')->label('Partner name (finance)'),
                TextEntry::make('company.name')
                    ->label('Company (validated)')
                    ->placeholder('— not linked —')
                    ->url(fn (RevenuePartner $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextEntry::make('phone')->label('Phone')->placeholder('— missing —'),
                IconEntry::make('is_active')->boolean()->label('Active'),
            ])->columns(2),
            Section::make('Service & billing')->schema([
                TextEntry::make('vasService.name')->label('Catalog service'),
                TextEntry::make('service_id')->label('Service ID')->placeholder('—')->copyable(),
                TextEntry::make('product_id')->label('Product ID')->placeholder('—')->copyable(),
                TextEntry::make('spid')->label('SPID')->placeholder('—')->copyable(),
                TextEntry::make('short_code')->label('Short code')->placeholder('—')->copyable(),
                TextEntry::make('creator.name')
                    ->label('Account manager')
                    ->placeholder('—')
                    ->visible(fn (): bool => static::viewerCanAccessAllRevenue()),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vasService.name')
                    ->label('Catalog service')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service_id')->label('Service ID')->searchable()->sortable()->copyable(),
                TextColumn::make('product_id')->label('Product ID')->searchable()->toggleable(isToggledHiddenByDefault: true)->copyable(),
                TextColumn::make('spid')->label('SPID')->searchable()->toggleable(isToggledHiddenByDefault: true)->copyable(),
                TextColumn::make('short_code')->label('Short code')->searchable()->toggleable(isToggledHiddenByDefault: true)->copyable(),
                TextColumn::make('customer_base_count')
                    ->label('Customer base')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('partner_name')->label('Partner name')->searchable()->sortable()->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->url(fn (RevenuePartner $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextColumn::make('phone')
                    ->placeholder('— missing —')
                    ->color(fn (?string $state): string => filled($state) ? 'gray' : 'danger')
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Account manager')
                    ->toggleable()
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('creator', function (Builder $q) use ($search): void {
                            $q->where('name', 'ilike', '%'.$search.'%')
                                ->orWhere('email', 'ilike', '%'.$search.'%')
                                ->orWhere('username', 'ilike', '%'.$search.'%');
                        });
                    })
                    ->visible(fn (): bool => static::viewerCanAccessAllRevenue()),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options()),
                SelectFilter::make('created_by_user_id')
                    ->label('Account manager')
                    ->options(fn (): array => static::accountManagerFilterOptions())
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        if ($value === '__unassigned__') {
                            return $query->whereNull('created_by_user_id');
                        }

                        return $query->where('created_by_user_id', (int) $value);
                    })
                    ->visible(fn (): bool => static::viewerCanAccessAllRevenue()),
                TernaryFilter::make('phone')
                    ->label('Phone')
                    ->placeholder('All')
                    ->trueLabel('Has phone')
                    ->falseLabel('Missing phone')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('phone')->where('phone', '!=', ''),
                        false: fn ($query) => $query->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', '')),
                    ),
                TernaryFilter::make('company_id')
                    ->label('Company')
                    ->placeholder('All')
                    ->trueLabel('Mapped to company')
                    ->falseLabel('Not mapped (no company)')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('company_id'),
                        false: fn ($query) => $query->whereNull('company_id'),
                    ),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export')
                    ->exporter(RevenuePartnerExporter::class)
                    ->columnMapping(true)
                    ->modalHeading('Export revenue partners')
                    ->fileDisk('local'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (RevenuePartner $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->label('Export selected')
                        ->exporter(RevenuePartnerExporter::class)
                        ->columnMapping(true)
                        ->modalHeading('Export selected revenue partners')
                        ->fileDisk('local'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MonthlyRevenueRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenuePartners::route('/'),
            'create' => CreateRevenuePartner::route('/create'),
            'view' => ViewRevenuePartner::route('/{record}'),
            'edit' => EditRevenuePartner::route('/{record}/edit'),
        ];
    }

    /**
     * Active staff users eligible as revenue partner owners (excludes super_admin).
     *
     * @return array<int, string>
     */
    public static function accountManagerOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Filter options: AMs who currently own partners, plus Unassigned.
     *
     * @return array<int|string, string>
     */
    public static function accountManagerFilterOptions(): array
    {
        $owners = User::query()
            ->whereIn(
                'id',
                RevenuePartner::query()
                    ->whereNotNull('created_by_user_id')
                    ->distinct()
                    ->pluck('created_by_user_id')
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $options = [];
        if (RevenuePartner::query()->whereNull('created_by_user_id')->exists()) {
            $options['__unassigned__'] = '— Unassigned —';
        }

        foreach ($owners as $id => $name) {
            $options[$id] = $name;
        }

        return $options;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vasService', 'creator', 'company']);
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllRevenue()) {
            return $query;
        }

        $ownerIds = AppSetting::revenueOwnerIdsFor($user);
        if ($ownerIds === null) {
            return $query;
        }

        // Account managers: own partners + AMs they are covering.
        return $query->whereIn('created_by_user_id', $ownerIds);
    }

    public static function viewerCanAccessAllRevenue(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessAllRevenue();
    }

    public static function viewerIsAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(['super_admin', 'admin']);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('Create:RevenuePartner');
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
}
