<?php

namespace App\Filament\Resources\BlogPosts;

use App\Filament\Resources\BlogPosts\Pages\CreateBlogPost;
use App\Filament\Resources\BlogPosts\Pages\EditBlogPost;
use App\Filament\Resources\BlogPosts\Pages\ListBlogPosts;
use App\Models\BlogPost;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BlogPostResource extends Resource
{
    protected static ?string $model = BlogPost::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'Blog';

    protected static ?string $modelLabel = 'Blog post';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, ?BlogPost $record): void {
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
            Textarea::make('excerpt')->rows(2)->columnSpanFull(),
            Textarea::make('body')->required()->rows(12)->columnSpanFull()
                ->helperText('Plain text or simple paragraphs. Line breaks are preserved on the website.'),
            FileUpload::make('cover_image')
                ->image()
                ->disk('public')
                ->directory('blog')
                ->visibility('public')
                ->imageEditor()
                ->columnSpanFull(),
            Toggle::make('is_published')->default(false),
            Toggle::make('is_featured')->default(false),
            DateTimePicker::make('published_at')->native(false),
            TextInput::make('sort_order')->numeric()->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('cover_image')->disk('public')->square(),
                TextColumn::make('title')->searchable()->sortable()->limit(40),
                IconColumn::make('is_published')->boolean()->label('Live'),
                IconColumn::make('is_featured')->boolean()->label('Featured'),
                TextColumn::make('published_at')->dateTime()->sortable(),
                TextColumn::make('sort_order')->label('#')->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogPosts::route('/'),
            'create' => CreateBlogPost::route('/create'),
            'edit' => EditBlogPost::route('/{record}/edit'),
        ];
    }
}
