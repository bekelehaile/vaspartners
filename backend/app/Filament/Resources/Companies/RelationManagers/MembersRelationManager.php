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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            ->description('Each company must have exactly one owner and may have many members.')
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
                        : CompanyRole::tryFrom((string) $record->company_role)) !== CompanyRole::Owner)
                    ->requiresConfirmation()
                    ->modalHeading('Transfer company ownership')
                    ->modalDescription(fn (Customer $record): string => "Make {$record->name} the sole owner. The current owner becomes a member.")
                    ->action(function (Customer $record, CompanyMembershipService $membership): void {
                        $membership->transferOwnership(
                            $this->getOwnerRecord(),
                            $record,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Ownership transferred')
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
