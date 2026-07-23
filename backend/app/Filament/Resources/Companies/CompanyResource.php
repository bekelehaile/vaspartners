<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\MembersRelationManager;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Companies';

    protected static ?string $modelLabel = 'Company';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('tin')
                ->label('TIN')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(64),
            TextInput::make('phone')->tel()->maxLength(32),
            TextInput::make('email')->email()->maxLength(255),
            Textarea::make('address')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')->label('Active')->default(true),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')->schema([
                TextEntry::make('public_id')->label('ID'),
                TextEntry::make('name'),
                TextEntry::make('tin')->label('TIN'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('is_active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextEntry::make('owner.name')->label('Owner')->placeholder('No owner')->color('success'),
                TextEntry::make('members_count')
                    ->label('Total people')
                    ->state(fn (Company $record): int => $record->members()->count()),
                TextEntry::make('address')->columnSpanFull()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('creator.name')->label('Created by partner')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('tin')->label('TIN')->searchable()->sortable(),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('email')->toggleable(),
                TextColumn::make('members_count')
                    ->counts('members')
                    ->label('Members')
                    ->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            ChangeRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Companies are created by partners (create/attach flow).
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canForceDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
