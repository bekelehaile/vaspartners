<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Enums\ApprovalAction;
use App\Models\TicketApprovalStep;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ApprovalStepsRelationManager extends RelationManager
{
    protected static string $relationship = 'approvalSteps';

    protected static ?string $title = 'Approval log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->approvalSteps()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Immutable record of each manager approval decision: who acted, what they decided, and when. Empty until the request enters the approval chain after documents pass review.')
            ->emptyStateHeading('No approval decisions yet')
            ->emptyStateDescription('This request has not reached manager approval. Document checks and status changes appear under Document reviews and Status history.')
            ->modifyQueryUsing(fn ($query) => $query->with(['approver', 'escalatedTo'])->oldest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('approver.name')
                    ->label('Approver')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->label('Decision')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ApprovalAction
                        ? $state->label()
                        : ApprovalAction::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->color(fn ($state): string => match ($state instanceof ApprovalAction ? $state : ApprovalAction::tryFrom((string) $state)) {
                        ApprovalAction::Approved => 'success',
                        ApprovalAction::Rejected => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_final')
                    ->label('Final')
                    ->boolean(),
                TextColumn::make('document_review_snapshot')
                    ->label('Doc review at decision')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'passed' => 'Passed',
                        'failed' => 'Failed',
                        'pending' => 'Pending',
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'passed' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('escalatedTo.name')
                    ->label('Escalated to')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->paginated([10, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
