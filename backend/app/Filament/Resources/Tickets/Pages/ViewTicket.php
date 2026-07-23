<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
        ];
    }
}
