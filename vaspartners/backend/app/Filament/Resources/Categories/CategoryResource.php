<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Groups';

    protected static ?string $modelLabel = 'Group';

    protected static ?string $pluralModelLabel = 'Groups';

    protected static ?int $navigationSort = 0;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('key', [Category::KEY_GROUP_1, Category::KEY_GROUP_2]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Group')->schema([
                TextInput::make('key')
                    ->label('Stable key')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Internal id (group_1 / group_2). Does not change when you rename the label.'),
                TextInput::make('name')
                    ->label('Display name')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Shown to staff and partners. Rename freely (e.g. Team 1, FinTech).'),
                Textarea::make('description')->columnSpanFull(),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->label('Display name')->searchable()->sortable(),
                TextColumn::make('key')->badge()->sortable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('services_count')
                    ->counts('services')
                    ->label('Services'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
