<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Enums\ProvisioningStatus;
use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use App\Models\ServiceProvisioning;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServiceProvisioningsRelationManager extends RelationManager
{
    protected static string $relationship = 'provisionings';

    protected static ?string $title = 'Service provision';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->provisionings()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Activation cases (PRV) for this subscription. Open a case for section steps and partner messaging.')
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'company']))
            ->columns([
                TextColumn::make('public_id')->label('PRV')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ProvisioningStatus
                        ? $state->label()
                        : (ProvisioningStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof ProvisioningStatus
                        ? $state
                        : ProvisioningStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextColumn::make('current_step_key')->label('Current step')->toggleable(),
                TextColumn::make('overall_days')
                    ->label('Overall days')
                    ->state(fn (ServiceProvisioning $record): int => $record->overallElapsedDays()),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ProvisioningStatus::cases())->mapWithKeys(
                        fn (ProvisioningStatus $s) => [$s->value => $s->label()]
                    )->all()
                ),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Open')
                    ->url(fn (ServiceProvisioning $record): string => ServiceProvisioningResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No service provision yet')
            ->emptyStateDescription('When the partner starts service activation after contract sign-off, cases appear here.');
    }
}
