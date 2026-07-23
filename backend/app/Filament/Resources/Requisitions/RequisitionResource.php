<?php

namespace App\Filament\Resources\Requisitions;

use App\Filament\Resources\Requisitions\Pages\CreateRequisition;
use App\Filament\Resources\Requisitions\Pages\EditRequisition;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Requisition;
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

class RequisitionResource extends Resource
{
    protected static ?string $model = Requisition::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Request types';

    protected static ?string $modelLabel = 'Request type';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->schema([
                TextInput::make('name')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, ?Requisition $record) => $record || $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                TextInput::make('code')->required()->unique(ignoreRecord: true)
                    ->helperText('Stable key used by APIs and seeders (new, renew, terminate, …)'),
                Textarea::make('description')->columnSpanFull(),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
            ])->columns(2),
            Section::make('Subscription behavior')->description('Controls how this request type affects subscriptions. Configure per type — do not hard-code in code.')
                ->schema([
                    Toggle::make('creates_subscription')->label('Creates subscription (e.g. New)'),
                    Toggle::make('requires_active_subscription')->label('Requires active subscription'),
                    Toggle::make('renews_subscription')->label('Renews / extends subscription'),
                    Toggle::make('terminates_subscription')->label('Terminates subscription'),
                    Toggle::make('is_system')->label('System type (protect from accidental delete)')->disabled(fn (?Requisition $record) => (bool) $record?->is_system),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                IconColumn::make('creates_subscription')->boolean()->label('New'),
                IconColumn::make('renews_subscription')->boolean()->label('Renew'),
                IconColumn::make('terminates_subscription')->boolean()->label('Terminate'),
                IconColumn::make('requires_active_subscription')->boolean()->label('Needs sub'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequisitions::route('/'),
            'create' => CreateRequisition::route('/create'),
            'edit' => EditRequisition::route('/{record}/edit'),
        ];
    }
}
