<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Service requests';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tt_number')->label('Request number')->searchable()->sortable(),
                TextColumn::make('service.name')->label('Service')->searchable(),
                TextColumn::make('requisition.name')->label('Type'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\TicketStatus
                        ? $state->label()
                        : (\App\Enums\TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof \App\Enums\TicketStatus
                        ? $state
                        : \App\Enums\TicketStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextColumn::make('document_review_status')
                    ->label('Docs')
                    ->badge()
                    ->formatStateUsing(fn ($record): string => $record->documentReviewLabel())
                    ->color(fn ($record): string => $record->documentReviewColor()),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open ticket')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => TicketResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
