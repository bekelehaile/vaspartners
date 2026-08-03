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
use Filament\Forms\Components\Toggle;
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

        $heading = $bits !== []
            ? implode(' · ', $bits)
            : 'Details, messages, attachments, approvals, and status history.';

        $user = auth()->user();
        if ($user && ! $user->canHandleCompanyServices($record->serviceCompany())) {
            return $heading.' · TIN not verified — account manager actions blocked';
        }

        return $heading;
    }

    /**
     * MVAS-style: note is internal unless staff ticks Notify partner (SMS + portal).
     */
    protected function notifyPartnerToggle(): Toggle
    {
        return Toggle::make('notify_partner')
            ->label('Notify partner')
            ->helperText('Off = internal only (staff log). On = partner gets portal + SMS for this action/note.')
            ->default(false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_sms')
                ->label('Send SMS')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('primary')
                ->visible(fn (Ticket $record): bool => (bool) auth()->user()?->canSendTicketSms()
                    && filled(TicketResource::ticketSmsPhone($record))
                    && TicketResource::accountManagerMayAct($record))
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
                    && $record->document_review_status !== DocumentReviewStatus::Passed
                    && TicketResource::accountManagerMayAct($record))
                ->form([
                    Select::make('result')->options([
                        DocumentReviewStatus::Passed->value => 'All documents OK',
                        DocumentReviewStatus::Failed->value => 'Documents missing/failed',
                    ])->required(),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->helperText('Internal unless you turn on Notify partner.'),
                    $this->notifyPartnerToggle(),
                ])
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    try {
                        $workflow->reviewDocuments(
                            $record,
                            auth()->user(),
                            DocumentReviewStatus::from($data['result']),
                            $data['note'] ?? null,
                            (bool) ($data['notify_partner'] ?? false),
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
                ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id()
                    && TicketResource::accountManagerMayAct($record))
                ->modalHeading('Approve this request')
                ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp.')
                ->form(function (Ticket $record): array {
                    $fields = [
                        Textarea::make('note')->label('Note (optional)'),
                    ];

                    // Intermediate hand-off stays internal (MVAS). Only final / reject surfaces notify.
                    $isFinal = app(TicketWorkflowService::class)->isFinalApprover($record, auth()->user());
                    if ($isFinal) {
                        $fields[] = $this->notifyPartnerToggle();
                    } else {
                        $fields[] = Toggle::make('notify_partner')
                            ->label('Notify partner')
                            ->helperText('Disabled while handing off to the next approver (internal only).')
                            ->default(false)
                            ->disabled()
                            ->dehydrated(true);
                    }

                    return $fields;
                })
                ->requiresConfirmation()
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    try {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::Approved,
                            $data['note'] ?? null,
                            (bool) ($data['notify_partner'] ?? false),
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
                ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id()
                    && TicketResource::accountManagerMayAct($record))
                ->modalHeading('Reject this request')
                ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp. A reason is required.')
                ->form([
                    Textarea::make('note')
                        ->label('Reason')
                        ->required()
                        ->minLength(3)
                        ->helperText('Required for the approval log. Partner only sees it if Notify partner is on.'),
                    $this->notifyPartnerToggle(),
                ])
                ->requiresConfirmation()
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    $workflow->decide(
                        $record,
                        auth()->user(),
                        ApprovalAction::Rejected,
                        $data['note'] ?? null,
                        (bool) ($data['notify_partner'] ?? false),
                    );
                }),
            Action::make('dispatcher_reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Ticket $record): bool => TicketResource::dispatcherMayReject($record))
                ->modalHeading('Reject this request')
                ->modalDescription('A reason is required. The partner will see it in the portal and by SMS.')
                ->form([
                    Textarea::make('note')
                        ->label('Reason')
                        ->required()
                        ->minLength(3)
                        ->helperText('Visible to the partner in the portal and included in the SMS.'),
                ])
                ->requiresConfirmation()
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    $workflow->rejectByDispatcher(
                        $record,
                        auth()->user(),
                        (string) ($data['note'] ?? ''),
                    );
                }),
            Action::make('close')
                ->label('Close')
                ->visible(fn (Ticket $record): bool => TicketResource::mayCloseTicket($record))
                ->form([
                    Textarea::make('note')->label('Note (optional)'),
                    $this->notifyPartnerToggle(),
                ])
                ->requiresConfirmation()
                ->modalDescription('New subscriptions need final approval first. After-sales close when required documents are verified (or none are required).')
                ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                    try {
                        $workflow->close(
                            $record,
                            auth()->user(),
                            $data['note'] ?? null,
                            (bool) ($data['notify_partner'] ?? false),
                        );
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
                        Notification::make()
                            ->title('Cannot close request')
                            ->body((string) $message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw $e;
                    }
                }),
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
