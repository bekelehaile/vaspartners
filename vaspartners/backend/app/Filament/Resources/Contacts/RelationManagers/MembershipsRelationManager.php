<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Enums\CompanyRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\CompanyMembership;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Company memberships';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('A contact can belong to many companies. Phone and email stay unique on the contact; memberships link them to each company.')
            ->modifyQueryUsing(fn ($query) => $query->with('company')->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id'))
            ->columns([
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('company.tin')->label('TIN')->placeholder('—'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole
                        ? $state->label()
                        : (CompanyRole::tryFrom((string) $state)?->label() ?? (string) $state)),
                IconColumn::make('is_active')
                    ->label('Access')
                    ->boolean(),
                IconColumn::make('is_current')
                    ->label('Current')
                    ->boolean()
                    ->getStateUsing(function (CompanyMembership $record): bool {
                        $contact = $this->getOwnerRecord();

                        return (int) $contact->current_company_id === (int) $record->company_id;
                    }),
                TextColumn::make('created_at')->label('Joined')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open_company')
                    ->label('View company')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (CompanyMembership $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null)
                    ->visible(fn (CompanyMembership $record): bool => filled($record->company)),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
