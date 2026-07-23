<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Resources\Faqs\Pages\EditFaq;
use App\Filament\Resources\Faqs\Pages\ListFaqs;
use App\Models\Faq;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'FAQs';

    protected static ?string $modelLabel = 'FAQ';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('answer')->required()->rows(5)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('question')->searchable()->limit(60),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqs::route('/'),
            'create' => CreateFaq::route('/create'),
            'edit' => EditFaq::route('/{record}/edit'),
        ];
    }
}
