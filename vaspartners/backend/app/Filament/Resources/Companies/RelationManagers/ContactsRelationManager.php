<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Support\IdentityLabels;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Contacts';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->members()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Partner contacts linked to this company through memberships.')
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query
                ->orderByRaw("CASE WHEN company_memberships.role = 'owner' THEN 0 ELSE 1 END"))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable(),
                TextColumn::make('email')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole
                        ? $state->label()
                        : (CompanyRole::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof CompanyRole ? $state->value : (string) $state) === 'owner'
                        ? 'success'
                        : 'gray'),
                IconColumn::make('pivot.is_active')
                    ->label('Membership')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('identity_verified_via')
                    ->label('Identity')
                    ->badge()
                    ->state(fn (Contact $record): ?string => $record->identityVerifiedViaValue())
                    ->formatStateUsing(fn ($state): string => filled($state)
                        ? IdentityLabels::via((string) $state)
                        : 'Unverified')
                    ->color(fn ($state): string => filled($state) ? 'success' : 'gray'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('pivot.created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->orderBy('company_memberships.created_at', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options([
                        CompanyRole::Owner->value => CompanyRole::Owner->label(),
                        CompanyRole::Member->value => CompanyRole::Member->label(),
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->where('company_memberships.role', $value);
                    }),
                TernaryFilter::make('membership_active')
                    ->label('Membership access')
                    ->queries(
                        true: fn ($query) => $query->where('company_memberships.is_active', true),
                        false: fn ($query) => $query->where('company_memberships.is_active', false),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (Contact $record): string => ContactResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => ContactResource::canEditContacts()),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
