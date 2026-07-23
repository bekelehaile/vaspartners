<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CompanyMembership;
use App\Models\Customer;
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
            ->description('A partner may belong to many companies. Each company still has exactly one owner.')
            ->modifyQueryUsing(fn ($query) => $query->with('customer')->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id'))
            ->columns([
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('customer.phone_number')->label('Phone')->searchable(),
                TextColumn::make('customer.email')->toggleable(),
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
                    ->url(fn (CompanyMembership $record): string => $record->customer
                        ? CustomerResource::getUrl('view', ['record' => $record->customer])
                        : '#'),
                Action::make('make_owner')
                    ->label('Make owner')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (CompanyMembership $record): bool => ! $record->isOwner() && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Transfer company ownership')
                    ->modalDescription(fn (CompanyMembership $record): string => 'Make '.($record->customer?->name ?: 'this partner').' the sole owner. The current owner becomes a member.')
                    ->action(function (CompanyMembership $record, CompanyMembershipService $membership): void {
                        try {
                            if (! $record->customer) {
                                return;
                            }
                            $membership->transferOwnership(
                                $this->getOwnerRecord(),
                                $record->customer,
                                auth()->user(),
                            );

                            Notification::make()
                                ->title('Ownership transferred')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Could not transfer ownership')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('disable_membership')
                    ->label('Disable access')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (CompanyMembership $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (CompanyMembership $record, CompanyMembershipService $membership): void {
                        try {
                            if (! $record->customer) {
                                return;
                            }
                            $membership->setMembershipActive(
                                $this->getOwnerRecord(),
                                $record->customer,
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
                        if (! $record->customer) {
                            return;
                        }
                        $membership->setMembershipActive(
                            $this->getOwnerRecord(),
                            $record->customer,
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
