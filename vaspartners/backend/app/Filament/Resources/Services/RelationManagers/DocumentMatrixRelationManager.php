<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentMatrixRelationManager extends RelationManager
{
    protected static string $relationship = 'documentMatrix';

    protected static ?string $title = 'Required documents by request type';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('requisition_id')
                ->relationship('requisition', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('document_type_id')
                ->relationship('documentType', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Toggle::make('is_required')->default(true),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->columns([
                TextColumn::make('requisition.name')
                    ->label('Request type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('documentType.name')
                    ->label('Document')
                    ->sortable()
                    ->searchable(),
                IconColumn::make('is_required')->boolean(),
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('requisition_id')
                    ->label('Request type')
                    ->relationship('requisition', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_required')
                    ->label('Required')
                    ->options([
                        '1' => 'Required',
                        '0' => 'Optional',
                    ]),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}
