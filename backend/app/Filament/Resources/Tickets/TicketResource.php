<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\DocumentsRelationManager;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            TextEntry::make('status')->badge(),
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
            DocumentsRelationManager::class,
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
                TextColumn::make('document_review_status')->label('Docs')->badge(),
                TextColumn::make('assignee.name')->label('AM'),
                TextColumn::make('currentApprover.name')->label('Approver'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(
                    fn (TicketStatus $s) => [$s->value => $s->label()]
                )),
                SelectFilter::make('queue')
                    ->label('Queue')
                    ->options([
                        'recent' => 'Unassigned (open)',
                        'my' => 'Assigned to me',
                        'approval' => 'My approval queue',
                        'closed' => 'Closed',
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        $userId = auth()->id();

                        return match ($value) {
                            'recent' => $query->where('status', TicketStatus::Open)->whereNull('assigned_to_user_id'),
                            'my' => $query->where('assigned_to_user_id', $userId),
                            'approval' => $query->where('current_approver_user_id', $userId)
                                ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed]),
                            'closed' => $query->where('status', TicketStatus::Closed),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
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
                    ->visible(fn (Ticket $record) => $record->assigned_to_user_id === auth()->id() && blank($record->current_approver_user_id) && $record->status === TicketStatus::InProgress)
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
                Action::make('decide')
                    ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id())
                    ->form([
                        Select::make('action')->options([
                            ApprovalAction::Approved->value => 'Approve',
                            ApprovalAction::Rejected->value => 'Reject',
                        ])->required(),
                        Textarea::make('note'),
                    ])
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::from($data['action']),
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('close')
                    ->visible(fn (Ticket $record) => in_array($record->status, [TicketStatus::Completed, TicketStatus::InProgress], true)
                        && ($record->assigned_to_user_id === auth()->id() || auth()->user()?->is_management))
                    ->requiresConfirmation()
                    ->action(fn (Ticket $record, TicketWorkflowService $workflow) => $workflow->close($record, auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
