<?php

namespace App\Filament\Resources\ServiceProvisionings\RelationManagers;

use App\Enums\ProvisioningStepStatus;
use App\Filament\Resources\ServiceProvisionings\Support\ProvisioningStepActions;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use App\Services\Provisioning\ProvisioningWorkflowRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
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
            ->description('Section handoffs for this activation. Waiting rows show Actions (Submit); View is always available.')
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
                    ->icon(false)
                    ->color('gray')
                    ->button()
                    ->slideOver()
                    ->modalHeading(fn (ServiceProvisioningStep $record): string => $record->label ?: 'Follow-up step')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        Grid::make(2)->schema([
                            TextInput::make('what')->label('What')->disabled()->dehydrated(false),
                            TextInput::make('where')->label('Where')->disabled()->dehydrated(false),
                            TextInput::make('status')->label('Status')->disabled()->dehydrated(false),
                            TextInput::make('days')->label('Days in step')->disabled()->dehydrated(false),
                            Textarea::make('remark')->label('Remark')->rows(3)->disabled()->dehydrated(false)->columnSpanFull(),
                        ]),
                    ])
                    ->fillForm(fn (ServiceProvisioningStep $record): array => [
                        'what' => $record->label,
                        'where' => $record->whereLabel(),
                        'status' => $record->status?->label() ?? '—',
                        'days' => (string) $record->daysInStep(),
                        'remark' => filled($record->remark) ? (string) $record->remark : '—',
                    ]),
                ActionGroup::make([
                    Action::make('submit_step')
                        ->label(fn (ServiceProvisioningStep $record): string => ProvisioningStepActions::submitToLabel($record))
                        ->icon(false)
                        ->color('primary')
                        ->slideOver()
                        ->modalHeading(fn (ServiceProvisioningStep $record): string => ProvisioningStepActions::submitToLabel($record))
                        ->modalDescription(fn (ServiceProvisioningStep $record): string => $record->whereLabel()
                            .(filled($record->label) ? ' · '.$record->label : ''))
                        ->form(function (ServiceProvisioningStep $record): array {
                            $case = $this->getOwnerRecord();
                            $def = app(ProvisioningWorkflowRegistry::class)
                                ->step($case->workflow_key, $record->step_key) ?? [];

                            return ProvisioningStepActions::formFields($def);
                        })
                        ->action(function (ServiceProvisioningStep $record, array $data): void {
                            /** @var ServiceProvisioning $case */
                            $case = $this->getOwnerRecord();
                            ProvisioningStepActions::runStep($case, (string) $record->step_key, $data);
                            $this->getOwnerRecord()->refresh()->load('steps');
                            $this->resetTable();
                        }),
                ])
                    ->label('Actions')
                    ->icon(false)
                    ->color('primary')
                    ->button()
                    ->visible(function (ServiceProvisioningStep $record): bool {
                        $case = $this->getOwnerRecord();
                        if (! $case instanceof ServiceProvisioning) {
                            return false;
                        }

                        return $record->status === ProvisioningStepStatus::Waiting
                            && ProvisioningStepActions::userCanActOnStep(auth()->user(), $record, $case);
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No follow-up steps')
            ->emptyStateDescription('Steps are created when the partner submits a service activation request.')
            ->paginated(false);
    }
}
