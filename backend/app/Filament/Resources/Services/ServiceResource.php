<?php

namespace App\Filament\Resources\Services;

use App\Enums\RenewalInterval;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\RelationManagers\DocumentMatrixRelationManager;
use App\Filament\Resources\Services\RelationManagers\FinalApproversRelationManager;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')->required()->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, ?Service $record): void {
                        if ($record) {
                            return;
                        }
                        $set('slug', Str::slug((string) $state));
                    }),
                TextInput::make('slug')
                    ->hidden()
                    ->dehydrated()
                    ->required()
                    ->unique(ignoreRecord: true),
                Textarea::make('description')->columnSpanFull(),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
                Select::make('requisitions')
                    ->relationship('requisitions', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('Which request types partners can open for this service'),
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('category.name'),
            TextColumn::make('renewal_interval')->badge(),
            IconColumn::make('is_subscription_based')->boolean()->label('Subs'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
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
}
