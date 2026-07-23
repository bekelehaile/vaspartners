<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Models\DocumentType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    /** @return array<string, string> */
    public static function mimeOptions(): array
    {
        return [
            'pdf' => 'PDF (.pdf)',
            'doc' => 'Word (.doc)',
            'docx' => 'Word (.docx)',
            'xls' => 'Excel (.xls)',
            'xlsx' => 'Excel (.xlsx)',
            'png' => 'PNG (.png)',
            'jpg' => 'JPEG (.jpg)',
            'jpeg' => 'JPEG (.jpeg)',
            'gif' => 'GIF (.gif)',
            'webp' => 'WebP (.webp)',
            'txt' => 'Text (.txt)',
            'csv' => 'CSV (.csv)',
            'zip' => 'ZIP (.zip)',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')
                ->hidden()
                ->dehydrated()
                ->unique(ignoreRecord: true),
            Select::make('accepted_mimes')
                ->label('Accepted mimes')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(static::mimeOptions())
                ->default(['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'])
                ->required()
                ->helperText('Select one or more file extensions allowed for this document type.')
                ->afterStateHydrated(function (Select $component, mixed $state): void {
                    if (is_string($state)) {
                        $component->state(
                            array_values(array_filter(array_map('trim', explode(',', $state))))
                        );
                    }
                })
                ->dehydrateStateUsing(fn (mixed $state): string => is_array($state)
                    ? implode(',', array_values(array_filter($state)))
                    : (string) $state),
            TextInput::make('max_size_kb')
                ->numeric()
                ->default(5120)
                ->required()
                ->minValue(1)
                ->maxValue(51200)
                ->helperText('Hard limit enforced on portal uploads (kilobytes).'),
            Textarea::make('description')->columnSpanFull(),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('accepted_mimes')
                ->label('Mimes')
                ->badge()
                ->separator(',')
                ->wrap(),
            TextColumn::make('max_size_kb')->label('Max KB'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTypes::route('/'),
            'create' => CreateDocumentType::route('/create'),
            'edit' => EditDocumentType::route('/{record}/edit'),
        ];
    }
}
