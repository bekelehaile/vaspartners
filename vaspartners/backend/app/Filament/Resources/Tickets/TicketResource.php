<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\ApprovalStepsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\DocumentReviewsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\StatusHistoryRelationManager;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Tickets';

    protected static ?string $navigationLabel = 'All tickets';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'tt_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('tt_number')->label('Request ID'),
            TextEntry::make('status')
                ->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof TicketStatus
                    ? $state->label()
                    : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                ->color(fn ($state): string => match ($state instanceof TicketStatus ? $state : TicketStatus::tryFrom((string) $state)) {
                    TicketStatus::Completed, TicketStatus::Closed => 'success',
                    TicketStatus::Rejected => 'danger',
                    TicketStatus::InProgress => 'info',
                    TicketStatus::Open => 'warning',
                    default => 'gray',
                }),
            TextEntry::make('attachments_badge')
                ->label('Attachments')
                ->badge()
                ->state(function (Ticket $record): string {
                    return $record->attachmentStatus()['label'];
                })
                ->color(fn (Ticket $record): string => match ($record->attachmentStatus()['state']) {
                    'complete' => 'success',
                    'incomplete' => 'danger',
                    default => 'gray',
                }),
            TextEntry::make('missing_attachments')
                ->label('Missing required docs')
                ->state(function (Ticket $record): string {
                    $names = $record->attachmentStatus()['missing_names'] ?? [];

                    return $names === [] ? 'None — all required docs on file' : implode(', ', $names);
                })
                ->color(fn (Ticket $record): string => ($record->attachmentStatus()['missing_count'] ?? 0) > 0 ? 'danger' : 'success')
                ->columnSpanFull(),
            TextEntry::make('document_review_status')->label('Document review')->badge(),
            TextEntry::make('customer.name')->label('Customer'),
            TextEntry::make('customer.phone_number')->label('Phone'),
            TextEntry::make('service.name')->label('Service'),
            TextEntry::make('requisition.name')->label('Request type'),
            TextEntry::make('assignee.name')->label('Account manager'),
            TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
        ])->columns(2);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
            DocumentsRelationManager::class,
            ApprovalStepsRelationManager::class,
            DocumentReviewsRelationManager::class,
            StatusHistoryRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tt_number')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->toggleable(),
                TextColumn::make('customer.phone_number')->label('Phone')->toggleable(),
                TextColumn::make('service.name')->sortable(),
                TextColumn::make('requisition.name')->label('Type')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('attachments')
                    ->label('Attachments')
                    ->badge()
                    ->state(fn (Ticket $record): string => $record->attachmentStatus()['label'])
                    ->color(fn (Ticket $record): string => match ($record->attachmentStatus()['state']) {
                        'complete' => 'success',
                        'incomplete' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(function (Ticket $record): ?string {
                        $status = $record->attachmentStatus();
                        if ($status['state'] !== 'incomplete') {
                            return null;
                        }

                        return 'Missing: '.implode(', ', $status['missing_names']);
                    }),
                TextColumn::make('document_review_status')->label('Review')->badge()->toggleable(),
                TextColumn::make('assignee.name')->label('AM'),
                TextColumn::make('currentApprover.name')->label('Approver'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(
                    fn (TicketStatus $s) => [$s->value => $s->label()]
                )),
                SelectFilter::make('attachments')
                    ->label('Attachments')
                    ->options([
                        'incomplete' => 'Missing required docs',
                        'complete' => 'All required docs',
                        'none' => 'No required docs',
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        $missingRequired = function ($q) {
                            $q->selectRaw('1')
                                ->from('service_requisition_documents as srd')
                                ->join('document_types as dt', 'dt.id', '=', 'srd.document_type_id')
                                ->whereColumn('srd.service_id', 'tickets.service_id')
                                ->whereColumn('srd.requisition_id', 'tickets.requisition_id')
                                ->where('srd.is_required', true)
                                ->where('dt.is_active', true)
                                ->whereNull('dt.deleted_at')
                                ->where('dt.code', '!=', 'document-if-any')
                                ->where('dt.name', 'not like', '%if any%')
                                ->whereNotExists(function ($q2) {
                                    $q2->selectRaw('1')
                                        ->from('ticket_documents as td')
                                        ->whereColumn('td.ticket_id', 'tickets.id')
                                        ->whereColumn('td.document_type_id', 'srd.document_type_id')
                                        ->whereNull('td.deleted_at');
                                });
                        };

                        $hasRequired = function ($q) {
                            $q->selectRaw('1')
                                ->from('service_requisition_documents as srd')
                                ->join('document_types as dt', 'dt.id', '=', 'srd.document_type_id')
                                ->whereColumn('srd.service_id', 'tickets.service_id')
                                ->whereColumn('srd.requisition_id', 'tickets.requisition_id')
                                ->where('srd.is_required', true)
                                ->where('dt.is_active', true)
                                ->whereNull('dt.deleted_at')
                                ->where('dt.code', '!=', 'document-if-any')
                                ->where('dt.name', 'not like', '%if any%');
                        };

                        return match ($value) {
                            'incomplete' => $query->whereExists($missingRequired),
                            'complete' => $query->whereExists($hasRequired)->whereNotExists($missingRequired),
                            'none' => $query->whereNotExists($hasRequired),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Ticket $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('assign_to_me')
                    ->label('Assign to me')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Open
                        && blank($record->assigned_to_user_id)
                        && auth()->user()
                        && ! auth()->user()->is_management)
                    ->requiresConfirmation()
                    ->modalHeading('Take this ticket')
                    ->modalDescription('Assign this service request to yourself as account manager.')
                    ->action(function (Ticket $record, TicketWorkflowService $workflow) {
                        $workflow->assign(
                            $record,
                            auth()->user(),
                            auth()->user(),
                            null,
                            'Self-assigned by account manager',
                        );
                    }),
                Action::make('assign')
                    ->label('Assign AM')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Open
                        && blank($record->assigned_to_user_id))
                    ->form([
                        Select::make('assigned_to_user_id')
                            ->label('Account manager')
                            ->options(fn () => User::query()
                                ->where('is_active', true)
                                ->where('is_management', false)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('priority_id')
                            ->relationship('priority', 'name')
                            ->searchable()
                            ->preload(),
                        Textarea::make('note'),
                    ])
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->assign(
                            $record,
                            auth()->user(),
                            User::findOrFail($data['assigned_to_user_id']),
                            $data['priority_id'] ?? null,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('verify_docs')
                    ->label('Verify docs')
                    ->visible(fn (Ticket $record) => $record->assigned_to_user_id === auth()->id()
                        && blank($record->current_approver_user_id)
                        && in_array($record->status, [TicketStatus::InProgress, TicketStatus::Rejected], true)
                        && $record->document_review_status !== DocumentReviewStatus::Passed)
                    ->form([
                        Select::make('result')->options([
                            DocumentReviewStatus::Passed->value => 'All documents OK',
                            DocumentReviewStatus::Failed->value => 'Documents missing/failed',
                        ])->required(),
                        Textarea::make('note'),
                    ])
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->reviewDocuments(
                            $record,
                            auth()->user(),
                            DocumentReviewStatus::from($data['result']),
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id())
                    ->modalHeading('Approve this request')
                    ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp.')
                    ->form([
                        Textarea::make('note')->label('Note (optional)'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::Approved,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id())
                    ->modalHeading('Reject this request')
                    ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp. A reason is required.')
                    ->form([
                        Textarea::make('note')
                            ->label('Reason')
                            ->required()
                            ->minLength(3)
                            ->helperText('Shown on the approval log and used when sending the request back.'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::Rejected,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('close')
                    ->label('Close')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Completed
                        && ($record->assigned_to_user_id === auth()->id() || auth()->user()?->is_management))
                    ->requiresConfirmation()
                    ->action(fn (Ticket $record, TicketWorkflowService $workflow) => $workflow->close($record, auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assign')
                        ->label('Assign AM')
                        ->icon('heroicon-o-user-plus')
                        ->color('primary')
                        ->form([
                            Select::make('assigned_to_user_id')
                                ->label('Account manager')
                                ->options(fn () => User::query()
                                    ->where('is_active', true)
                                    ->where('is_management', false)
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->preload(),
                            Select::make('priority_id')
                                ->label('Priority')
                                ->options(fn () => Priority::query()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->preload(),
                            Textarea::make('note'),
                        ])
                        ->action(function (Collection $records, array $data, TicketWorkflowService $workflow): void {
                            $assignee = User::findOrFail($data['assigned_to_user_id']);
                            $assigner = auth()->user();
                            $assigned = 0;
                            $skipped = 0;

                            foreach ($records as $ticket) {
                                if ($ticket->status !== TicketStatus::Open || filled($ticket->assigned_to_user_id)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $workflow->assign(
                                        $ticket,
                                        $assigner,
                                        $assignee,
                                        $data['priority_id'] ?? null,
                                        $data['note'] ?? null,
                                    );
                                    $assigned++;
                                } catch (Throwable) {
                                    $skipped++;
                                }
                            }

                            if ($assigned > 0) {
                                Notification::make()
                                    ->title("Assigned {$assigned} ticket(s)")
                                    ->body($skipped > 0 ? "{$skipped} skipped (already assigned or not open)." : null)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('No tickets assigned')
                                    ->body('Only open, unassigned tickets can be bulk-assigned.')
                                    ->warning()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->visible(fn (): bool => (bool) auth()->user()?->can('Delete:Ticket')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user || $user->hasRole('super_admin') || $user->can('ViewAny:Ticket')) {
            return $query;
        }

        // Account managers: only tickets assigned to them or awaiting their approval
        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to_user_id', $user->id)
                ->orWhere('current_approver_user_id', $user->id);
        });
    }
}
