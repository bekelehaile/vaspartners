<?php

namespace App\Filament\Resources\RevenuePartners;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\RevenuePartners\Pages\CreateRevenuePartner;
use App\Filament\Resources\RevenuePartners\Pages\EditRevenuePartner;
use App\Filament\Resources\RevenuePartners\Pages\ListRevenuePartners;
use App\Filament\Resources\RevenuePartners\Pages\ViewRevenuePartner;
use App\Filament\Resources\RevenuePartners\RelationManagers\MonthlyRevenueRelationManager;
use App\Models\Company;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\RevenueCatalogServices;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
                    Select::make('company_id')
                        ->label('Partner name')
                        ->options(
                            fn (): array => Company::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $company = $state ? Company::query()->find($state) : null;
                            $set('partner_name', $company?->name);
                            $set('phone', PhoneNumber::normalizeNullable($company?->phone));
                        })
                        ->columnSpanFull(),
                    Hidden::make('partner_name')
                        ->dehydrated()
                        ->required(),
                    TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(32)
                        ->helperText('Last 9 digits for SMS. Fills from the company when available.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state))
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value === null || $value === '') {
                                return;
                            }
                            if (! PhoneNumber::isValidLocalMobile($value)) {
                                $fail('Phone must be a local mobile (9/7 + 8 digits).');
                            }
                        }),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(2),

            Section::make('Service & billing')
                ->schema([
                    Select::make('vas_service_id')
                        ->label('Catalog service')
                        ->options(RevenueCatalogServices::options())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText('For labeling / SMS wording only. Partners belong to the importing account manager.')
                        ->columnSpanFull(),
                    TextInput::make('service_id')
                        ->label('Service ID')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText('Finance endpoint ID. Monthly CSV matches this within your partner list.'),
                    TextInput::make('short_code')
                        ->label('Short code')
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText('Optional alternate match key. Unique when set.'),
                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Partner')->schema([
                TextEntry::make('partner_name')->label('Partner name'),
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->url(fn (RevenuePartner $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextEntry::make('phone')->label('Phone')->placeholder('— missing —'),
                IconEntry::make('is_active')->boolean()->label('Active'),
            ])->columns(2),
            Section::make('Service & billing')->schema([
                TextEntry::make('vasService.name')->label('Catalog service'),
                TextEntry::make('service_id')->label('Service ID')->copyable(),
                TextEntry::make('short_code')->label('Short code')->placeholder('—'),
                TextEntry::make('creator.name')->label('Account manager')->placeholder('—'),
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
                TextColumn::make('short_code')->label('Short code')->searchable()->toggleable(),
                TextColumn::make('partner_name')->searchable()->sortable()->wrap(),
                TextColumn::make('phone')
                    ->placeholder('— missing —')
                    ->color(fn (?string $state): string => filled($state) ? 'gray' : 'danger')
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Account manager')
                    ->toggleable()
                    ->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('partner_name')
            ->filters([
                SelectFilter::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options()),
                TernaryFilter::make('phone')
                    ->label('Phone')
                    ->placeholder('All')
                    ->trueLabel('Has phone')
                    ->falseLabel('Missing phone')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('phone')->where('phone', '!=', ''),
                        false: fn ($query) => $query->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', '')),
                    ),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (RevenuePartner $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([]);
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vasService', 'creator']);
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllRevenue()) {
            return $query;
        }

        // AMs: only partners they imported / created.
        return $query->where('created_by_user_id', $user->id);
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
