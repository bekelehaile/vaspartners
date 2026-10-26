<?php

namespace App\Filament\Resources\ServiceProvisionings\RelationManagers;

use App\Enums\ProvisioningStepStatus;
use App\Models\ServiceProvisioningStep;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'Follow-up';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->steps()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Department handoffs for this activation case. Use header actions on the case to complete waiting steps.')
            ->columns([
                TextColumn::make('seq')->label('#')->sortable(),
                TextColumn::make('label')->label('What')->wrap()->searchable(),
                TextColumn::make('where')
                    ->label('Where')
                    ->state(fn (ServiceProvisioningStep $record): string => $record->whereLabel())
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ProvisioningStepStatus
                        ? $state->label()
                        : (ProvisioningStepStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof ProvisioningStepStatus
                        ? $state
                        : ProvisioningStepStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextColumn::make('days')
                    ->label('Days')
                    ->state(fn (ServiceProvisioningStep $record): int => $record->daysInStep()),
                TextColumn::make('remark')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')->dateTime()->sortable()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ProvisioningStepStatus::cases())->mapWithKeys(
                        fn (ProvisioningStepStatus $s) => [$s->value => $s->label()]
                    )->all()
                ),
            ])
            ->defaultSort('seq')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (ServiceProvisioningStep $record): string => $record->label)
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        TextInput::make('what')->label('What')->disabled()->dehydrated(false),
                        TextInput::make('where')->label('Where')->disabled()->dehydrated(false),
                        TextInput::make('status')->label('Status')->disabled()->dehydrated(false),
                        TextInput::make('days')->label('Days in step')->disabled()->dehydrated(false),
                        Textarea::make('remark')->label('Remark')->rows(4)->disabled()->dehydrated(false)->columnSpanFull(),
                    ])
                    ->fillForm(fn (ServiceProvisioningStep $record): array => [
                        'what' => $record->label,
                        'where' => $record->whereLabel(),
                        'status' => $record->status?->label() ?? '—',
                        'days' => (string) $record->daysInStep(),
                        'remark' => filled($record->remark) ? (string) $record->remark : '—',
                    ]),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No follow-up steps')
            ->emptyStateDescription('Steps are created when the partner submits a service activation request.')
            ->paginated(false);
    }
}
