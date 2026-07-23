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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service')->schema([
                Select::make('category_id')->relationship('category', 'name')->required()->searchable(),
                TextInput::make('name')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, ?Service $record) => $record || $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Textarea::make('description')->columnSpanFull(),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
                Select::make('requisitions')
                    ->relationship('requisitions', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Which request types partners can open for this service'),
            ])->columns(2),
            Section::make('Subscription & renewal')->description('New subscriptions use yearly or bi-yearly renewal until terminated.')
                ->schema([
                    Toggle::make('is_subscription_based')->default(true),
                    Select::make('renewal_interval')
                        ->options(RenewalInterval::options())
                        ->helperText('Required for subscription-based services'),
                    TextInput::make('renewal_lead_days')->numeric()->default(30)
                        ->helperText('Days before period end to open a renewal request'),
                    Select::make('renewal_requisition_id')
                        ->relationship('renewalRequisition', 'name')
                        ->searchable()
                        ->helperText('Usually the Renew request type'),
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
