<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Models\Service;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FinalApproversRelationManager extends RelationManager
{
    protected static string $relationship = 'finalApprovers';

    protected static ?string $title = 'Final approvers by request type';

    public function form(Schema $schema): Schema
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();

        return $schema->components([
            Select::make('requisition_id')
                ->label('Request type')
                ->options(fn (): array => $service->requisitions()
                    ->where('creates_subscription', true)
                    ->orderBy('name')
                    ->pluck('name', 'requisitions.id')
                    ->all())
                ->required()
                ->searchable()
                ->preload()
                ->helperText('Only new-subscription request types need a final approver. After-sales types close with docs + AM.'),
            Select::make('user_id')
                ->label('Final approver')
                ->options(fn (): array => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->searchable()
                ->preload()
                ->helperText('Approves new-subscription requests before the AM can close.'),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();
        $missing = $this->missingRequisitionNames($service);

        return $table
            ->description($missing === []
                ? 'Final approvers are required for new-subscription request types only. After-sales types need docs verified, then the AM can close (including bulk close).'
                : 'Missing final approver for new-subscription type(s): '.implode(', ', $missing).'.')
            ->columns([
                TextColumn::make('requisition.name')->label('Request type')->sortable(),
                TextColumn::make('user.name')->label('Final approver')->sortable(),
                TextColumn::make('user.is_active')
                    ->label('Active')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->color(fn ($state): string => $state ? 'success' : 'danger'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add final approver')
                    ->after(function (): void {
                        $this->warnIfStillMissing();
                    }),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->after(function (): void {
                        $this->warnIfStillMissing();
                    }),
                \Filament\Actions\DeleteAction::make()
                    ->after(function (): void {
                        $this->warnIfStillMissing();
                    }),
            ])
            ->emptyStateHeading('No final approvers configured')
            ->emptyStateDescription('Add a final approver for each new-subscription request type on this service. After-sales request types do not need one.');
    }

    /**
     * @return list<string>
     */
    protected function missingRequisitionNames(Service $service): array
    {
        $workflow = app(TicketWorkflowService::class);

        return $service->requisitions()
            ->where('creates_subscription', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Model $requisition): bool => ! $workflow->hasFinalApproverConfigured(
                (int) $service->id,
                (int) $requisition->id,
            ))
            ->pluck('name')
            ->values()
            ->all();
    }

    protected function warnIfStillMissing(): void
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord()->fresh(['requisitions', 'finalApprovers.user']);
        $missing = $this->missingRequisitionNames($service);

        if ($missing === []) {
            return;
        }

        Notification::make()
            ->title('Final approvers still missing')
            ->body('Still need a final approver for: '.implode(', ', $missing).'.')
            ->warning()
            ->persistent()
            ->send();
    }
}
