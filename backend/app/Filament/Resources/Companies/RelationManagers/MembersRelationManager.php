<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CompanyRole;
use App\Filament\Resources\Customers\CustomerResource;
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
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Each company must have exactly one owner and may have many members. Disable a member to revoke company access without unlinking.')
            ->modifyQueryUsing(fn ($query) => $query->orderByRaw("CASE WHEN company_role = 'owner' THEN 0 ELSE 1 END")->orderBy('name'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('phone_number')->label('Phone')->searchable(),
                TextColumn::make('email')->toggleable(),
                TextColumn::make('company_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole
                        ? $state->label()
                        : (CompanyRole::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof CompanyRole ? $state->value : (string) $state) === 'owner'
                        ? 'success'
                        : 'gray'),
                IconColumn::make('company_membership_active')
                    ->label('Access')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('profile_completed_at')->label('Joined')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Customer $record): string => CustomerResource::getUrl('view', ['record' => $record])),
                Action::make('make_owner')
                    ->label('Make owner')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (Customer $record): bool => ($record->company_role instanceof CompanyRole
                        ? $record->company_role
                        : CompanyRole::tryFrom((string) $record->company_role)) !== CompanyRole::Owner
                        && $record->company_membership_active !== false)
                    ->requiresConfirmation()
                    ->modalHeading('Transfer company ownership')
                    ->modalDescription(fn (Customer $record): string => "Make {$record->name} the sole owner. The current owner becomes a member.")
                    ->action(function (Customer $record, CompanyMembershipService $membership): void {
                        try {
                            $membership->transferOwnership(
                                $this->getOwnerRecord(),
                                $record,
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
                    ->visible(fn (Customer $record): bool => $record->company_membership_active !== false)
                    ->requiresConfirmation()
                    ->modalHeading('Disable company access')
                    ->modalDescription(fn (Customer $record): string => "{$record->name} will stay linked but can no longer see company details or manage company services.")
                    ->action(function (Customer $record, CompanyMembershipService $membership): void {
                        try {
                            $membership->setMembershipActive(
                                $this->getOwnerRecord(),
                                $record,
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
                    ->visible(fn (Customer $record): bool => $record->company_membership_active === false)
                    ->requiresConfirmation()
                    ->modalHeading('Enable company access')
                    ->modalDescription(fn (Customer $record): string => "Restore {$record->name}'s access to company info and subscriptions.")
                    ->action(function (Customer $record, CompanyMembershipService $membership): void {
                        $membership->setMembershipActive(
                            $this->getOwnerRecord(),
                            $record,
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
