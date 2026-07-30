<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Services\SmsService;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key)->loadMissing([
            'contact.company',
            'service',
            'requisition',
            'category',
            'priority',
            'assignee',
            'currentApprover',
            'subscription.company',
            'subscription.service',
            'parentTicket',
        ]);

        if ($record instanceof Ticket) {
            $record = app(TicketWorkflowService::class)->enforceIncompleteMustBeRejected($record);
            app(\App\Services\TicketAuditTrailService::class)->backfillMissingHistory($record);
            $record->loadMissing([
                'contact.company',
                'service',
                'requisition',
                'category',
                'priority',
                'assignee',
                'currentApprover',
                'subscription.company',
                'subscription.service',
                'parentTicket',
                'statusHistories.actor',
            ]);
        }

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record->tt_number
            ? 'Request '.$record->tt_number
            : 'Request';
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $status = $record->status instanceof TicketStatus
            ? $record->status->label()
            : (string) $record->status;

        $bits = array_filter([
            $record->service?->name,
            $record->requisition?->name,
            $status,
            $record->contact?->name,
        ]);

        return $bits !== []
            ? implode(' · ', $bits)
            : 'Details, messages, attachments, approvals, and status history.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_sms')
                ->label('Send SMS')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('primary')
                ->visible(fn (Ticket $record): bool => (bool) auth()->user()?->canSendTicketSms()
                    && filled(TicketResource::ticketSmsPhone($record)))
                ->form([
                    Textarea::make('message')
                        ->label('SMS message')
                        ->required()
                        ->rows(5)
                        ->maxLength(640)
                        ->helperText(fn (Ticket $record): string => 'Ad-hoc SMS to contact '
                            .($record->contact?->phone_number ?: '—')
                            .' for request '.$record->tt_number
                            .'. Max 640 characters.'),
                ])
                ->requiresConfirmation()
                ->modalHeading(fn (Ticket $record): string => 'Send SMS for '.$record->tt_number)
                ->action(function (Ticket $record, array $data, SmsService $sms): void {
                    TicketResource::dispatchTicketSms($record, (string) ($data['message'] ?? ''), $sms);
                }),
            Action::make('verify_docs')
                ->label('Verify docs')
                ->visible(fn (Ticket $record) => $record->assigned_to_user_id === auth()->id()
                    && blank($record->current_approver_user_id)
                    && in_array($record->status, [
                        TicketStatus::Open,
                        TicketStatus::InProgress,
                        TicketStatus::Rejected,
                    ], true)
                    && $record->document_review_status !== DocumentReviewStatus::Passed)
                ->form([
                    Select::make('result')->options([
                        DocumentReviewStatus::Passed->value => 'All documents OK',
                        DocumentReviewStatus::Failed->value => 'Documents missing/failed',
                    ])->required(),
                    Textarea::make('note'),
                ])
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    try {
                        $workflow->reviewDocuments(
                            $record,
                            auth()->user(),
                            DocumentReviewStatus::from($data['result']),
                            $data['note'] ?? null,
                        );
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
                        Notification::make()
                            ->title('Next approver is not found')
                            ->body((string) $message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw $e;
                    }
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
                    try {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::Approved,
                            $data['note'] ?? null,
                        );
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
                        Notification::make()
                            ->title(
                                str_contains(strtolower((string) $message), 'approver')
                                    ? 'Next approver is not found'
                                    : 'Approval failed'
                            )
                            ->body((string) $message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw $e;
                    }
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
            DeleteAction::make()
                ->visible(fn (Ticket $record): bool => (bool) auth()->user()?->can('delete', $record)
                    && $record->status === TicketStatus::Open)
                ->modalHeading(fn (Ticket $record): string => 'Delete pending request '.$record->tt_number)
                ->modalDescription('Only pending requests can be deleted.')
                ->successNotificationTitle('Pending request deleted')
                ->successRedirectUrl(TicketResource::getUrl('index')),
        ];
    }
}
