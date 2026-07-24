<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FinalApproversRelationManager extends RelationManager
{
    protected static string $relationship = 'finalApprovers';

    protected static ?string $title = 'Final approvers by request type';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('requisition_id')
                ->relationship('requisition', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('user_id')
                ->relationship('user', 'name')
                ->required()
                ->searchable()
                ->preload(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requisition.name')->label('Request type'),
                TextColumn::make('user.name')->label('Final approver'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->recordActions([
                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}
