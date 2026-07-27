<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\CompanyMembership;
use App\Services\CompanyMembershipService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Company owners can enable/disable members and grant permissions in the partner portal. Admins can also toggle access here. Ownership transfers are requested by the owner (with a letter PDF) and approved under Company change requests.')
            ->modifyQueryUsing(fn ($query) => $query->with('contact')->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id'))
            ->columns([
                TextColumn::make('contact.name')->searchable()->sortable(),
                TextColumn::make('contact.phone_number')->label('Phone')->searchable(),
                TextColumn::make('contact.email')->toggleable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole
                        ? $state->label()
                        : (CompanyRole::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof CompanyRole ? $state->value : (string) $state) === 'owner'
                        ? 'success'
                        : 'gray'),
                IconColumn::make('is_active')
                    ->label('Access')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('created_at')->label('Joined')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CompanyMembership $record): string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : '#'),
                Action::make('disable_membership')
                    ->label('Disable access')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (CompanyMembership $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (CompanyMembership $record, CompanyMembershipService $membership): void {
                        try {
                            if (! $record->contact) {
                                return;
                            }
                            $membership->setMembershipActive(
                                $this->getOwnerRecord(),
                                $record->contact,
                                false,
                                auth()->user(),
                            );

                            Notification::make()
                                ->title('Membership disabled')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Could not disable membership')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('enable_membership')
                    ->label('Enable access')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (CompanyMembership $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (CompanyMembership $record, CompanyMembershipService $membership): void {
                        if (! $record->contact) {
                            return;
                        }
                        $membership->setMembershipActive(
                            $this->getOwnerRecord(),
                            $record->contact,
                            true,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Membership enabled')
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
