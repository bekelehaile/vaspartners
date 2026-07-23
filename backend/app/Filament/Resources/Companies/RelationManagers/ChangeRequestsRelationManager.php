<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Change requests';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'reviewer'])->latest('id'))
            ->columns([
                TextColumn::make('public_id')->label('ID')->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CompanyChangeType ? $state->label() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state instanceof CompanyChangeStatus ? $state->value : $state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('customer.name')->label('Partner'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CompanyChangeRequest $record): string => CompanyChangeRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
