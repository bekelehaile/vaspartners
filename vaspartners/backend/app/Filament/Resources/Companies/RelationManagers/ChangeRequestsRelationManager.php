<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Change requests';

    public function isReadOnly(): bool
    {
        // Super admin may delete from this relation; others stay view-only.
        return ! CompanyChangeRequestResource::canDeleteAny();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->changeRequests()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['contact', 'reviewer']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('public_id')->label('Request number')->searchable(),
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
                TextColumn::make('contact.name')->label('Partner'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CompanyChangeRequest $record): string => CompanyChangeRequestResource::getUrl('view', ['record' => $record])),
                DeleteAction::make()
                    ->visible(fn (CompanyChangeRequest $record): bool => CompanyChangeRequestResource::canDelete($record))
                    ->requiresConfirmation(),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
