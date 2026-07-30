<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Services\TicketAuditTrailService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status audit trail';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if ($ownerRecord instanceof Ticket) {
            app(TicketAuditTrailService::class)->backfillMissingHistory($ownerRecord);
        }

        $count = $ownerRecord->statusHistories()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        /** @var Ticket $owner */
        $owner = $this->getOwnerRecord();
        if ($owner instanceof Ticket) {
            app(TicketAuditTrailService::class)->backfillMissingHistory($owner);
        }

        return $table
            ->description('Who changed each status and when — submitted, assigned, in progress, approved, completed, closed, rejected.')
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->state(function (TicketStatusHistory $record): string {
                        $meta = is_array($record->meta) ? $record->meta : [];
                        $event = $meta['event'] ?? null;
                        if (is_string($event) && $event !== '') {
                            return match ($event) {
                                'submitted' => 'Submitted',
                                'pending' => 'Pending',
                                'assigned' => 'Assigned',
                                'reassigned' => 'Reassigned',
                                'in_progress' => 'In progress',
                                'approved', 'completed' => 'Approved',
                                'closed' => 'Closed',
                                'rejected' => 'Rejected',
                                default => ucfirst(str_replace('_', ' ', $event)),
                            };
                        }

                        return TicketStatus::tryFrom((string) $record->to_status)?->label()
                            ?? (string) $record->to_status;
                    })
                    ->color(fn (TicketStatusHistory $record): string => match (
                        (is_array($record->meta) ? ($record->meta['event'] ?? null) : null) ?: $record->to_status
                    ) {
                        'approved', 'completed', TicketStatus::Completed->value => 'success',
                        'closed', TicketStatus::Closed->value => 'gray',
                        'rejected', TicketStatus::Rejected->value => 'danger',
                        'assigned', 'reassigned', 'in_progress', TicketStatus::InProgress->value => 'info',
                        'submitted', 'pending', TicketStatus::Open->value => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('actor_name')
                    ->label('Who')
                    ->state(function (TicketStatusHistory $record): string {
                        $meta = is_array($record->meta) ? $record->meta : [];
                        $actor = $record->actor;
                        $name = $actor?->name
                            ?? (is_string($meta['approver_name'] ?? null) ? $meta['approver_name'] : null)
                            ?? (is_string($meta['assigner_name'] ?? null) ? $meta['assigner_name'] : null);

                        if (! $name) {
                            $name = ($record->actor_type || $record->actor_id) ? 'Staff' : 'System';
                        }

                        if (! empty($meta['assignee_name'])) {
                            return $name.' → '.$meta['assignee_name'];
                        }

                        return $name;
                    })
                    ->wrap(),
                TextColumn::make('from_status')
                    ->label('From')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TicketStatus::tryFrom((string) $state)?->label() ?? ($state ?: '—'))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('to_status')
                    ->label('To')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TicketStatus::tryFrom((string) $state)?->label() ?? ($state ?: '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed', 'closed' => 'success',
                        'rejected' => 'danger',
                        'in_progress' => 'info',
                        'open' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(100)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25, 50, 100])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
