<?php

namespace App\Filament\Resources\Services;

use App\Enums\RenewalInterval;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\RelationManagers\DocumentMatrixRelationManager;
use App\Filament\Resources\Services\RelationManagers\FinalApproversRelationManager;
use App\Models\Service;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service')->schema([
                Select::make('group_ids')
                    ->label('Groups')
                    ->options(fn (): array => \App\Models\Category::query()
                        ->operationalGroups()
                        ->pluck('name', 'id')
                        ->all())
                    ->multiple()
                    ->required()
                    ->minItems(1)
                    ->searchable()
                    ->preload()
                    ->helperText('Assign Group 1, Group 2, or both. Display names are editable under Catalog → Groups.'),
                TextInput::make('name')->required()->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, ?Service $record): void {
                        if ($record) {
                            return;
                        }
                        $slug = Str::slug((string) $state);
                        $set('slug', $slug);
                        $probe = new Service(['name' => (string) $state, 'slug' => $slug]);
                        if ($probe->isPremium()) {
                            $set('has_monthly_revenue', true);
                        }
                    }),
                TextInput::make('slug')
                    ->hidden()
                    ->dehydrated()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('sort_order')->numeric()->default(0),
                Select::make('requisitions')
                    ->relationship('requisitions', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull()
                    ->helperText('New subscription = needs Final approver. After-sales (maintain/renew/terminate/etc.) = docs + AM close only, no approval.'),
            ])->columns(2),
            Section::make('Subscription & renewal')
                ->description('Turn on for services that create a renewable subscription. Turn off for one-off services (e.g. CRBT) — no subscription is created and no automatic renewal runs.')
                ->schema([
                    Toggle::make('is_subscription_based')
                        ->label('Subscription based')
                        ->helperText('Off = no subscription lifecycle and no automatic renewal.')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state) {
                                $set('renewal_interval', RenewalInterval::Yearly->value);
                                $set('renewal_lead_days', 30);

                                return;
                            }

                            $set('renewal_interval', null);
                            $set('renewal_lead_days', 30);
                            $set('renewal_requisition_id', null);
                        }),
                    Select::make('renewal_interval')
                        ->label('Renewal interval')
                        ->options(RenewalInterval::options())
                        ->default(RenewalInterval::Yearly->value)
                        ->required(fn (Get $get): bool => (bool) $get('is_subscription_based'))
                        ->visible(fn (Get $get): bool => (bool) $get('is_subscription_based'))
                        ->helperText('How often the subscription renews (default yearly).'),
                    TextInput::make('renewal_lead_days')
                        ->label('Renewal lead days')
                        ->numeric()
                        ->default(30)
                        ->required(fn (Get $get): bool => (bool) $get('is_subscription_based'))
                        ->visible(fn (Get $get): bool => (bool) $get('is_subscription_based'))
                        ->helperText('Days before period end to open an automatic renewal request.'),
                    Select::make('renewal_requisition_id')
                        ->label('Renewal request type')
                        ->relationship('renewalRequisition', 'name')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => (bool) $get('is_subscription_based'))
                        ->helperText('Usually the Renewal request type.'),
                ])->columns(2),
            Section::make('Details')
                ->schema([
                    RichEditor::make('description')
                        ->label('Description')
                        ->columnSpanFull()
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'link'],
                            ['bulletList', 'orderedList'],
                            ['undo', 'redo'],
                        ]),
                    FileUpload::make('image')
                        ->label('Image')
                        ->image()
                        ->disk('public')
                        ->directory('services')
                        ->visibility('public')
                        ->imageEditor()
                        ->helperText('Shown on the website home page and service detail page.')
                        ->columnSpanFull(),
                    Toggle::make('is_active')->default(true),
                    Toggle::make('has_monthly_revenue')
                        ->label('Monthly revenue')
                        ->helperText('Show in Monthly Revenue catalog. Premium services default on.')
                        ->default(false),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'subscriptions as companies_count' => fn (Builder $q) => $q
                    ->selectRaw('count(distinct company_id)'),
            ]))
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                ImageColumn::make('image')->disk('public')->square(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('categories.name')
                    ->label('Groups')
                    ->badge()
                    ->separator(','),
                TextColumn::make('companies_count')
                    ->label('Companies')
                    ->sortable()
                    ->alignEnd()
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Service $record): ?string => ((int) ($record->companies_count ?? 0)) > 0 ? 'primary' : 'gray')
                    ->url(function (Service $record): ?string {
                        if ((int) ($record->companies_count ?? 0) < 1) {
                            return null;
                        }

                        return CompanyResource::getUrl('index').'?'.http_build_query([
                            'filters' => [
                                'service_id' => [
                                    'values' => [(string) $record->getKey()],
                                ],
                            ],
                        ]);
                    })
                    ->tooltip(fn (Service $record): ?string => ((int) ($record->companies_count ?? 0)) > 0
                        ? 'View companies with a subscription to '.$record->name
                        : null),
                TextColumn::make('renewal_interval')->badge(),
                IconColumn::make('is_subscription_based')->boolean()->label('Subs'),
                IconColumn::make('has_monthly_revenue')->boolean()->label('Revenue')->toggleable(),
                IconColumn::make('is_active')->boolean(),
            ])->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Service $record): bool => static::canDelete($record))
                    ->modalHeading(fn (Service $record): string => 'Delete service '.$record->name)
                    ->modalDescription('Only allowed when this service has no pending or in-progress requests. Closed and rejected history is kept.')
                    ->successNotificationTitle('Service deleted'),
            ])
            ->filters([
                TernaryFilter::make('has_monthly_revenue')->label('Monthly revenue'),
                TernaryFilter::make('is_active')->label('Active'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentMatrixRelationManager::class,
            FinalApproversRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Service
            && parent::canDelete($record)
            && ! $record->hasActiveRequests();
    }
}
