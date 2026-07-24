<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Enums\BulkMessageStatus;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Models\BulkMessage;
use App\Services\BulkMessageService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewBulkMessage extends ViewRecord
{
    protected static string $resource = BulkMessageResource::class;

    protected function getHeaderActions(): array
    {
        /** @var BulkMessage $record */
        $record = $this->getRecord();

        return [
            Action::make('send')
                ->label('Send pending')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => in_array($record->status, [
                    BulkMessageStatus::Draft,
                    BulkMessageStatus::Completed,
                    BulkMessageStatus::Failed,
                ], true))
                ->requiresConfirmation()
                ->action(function (BulkMessageService $bulkMessages) use ($record): void {
                    try {
                        $bulkMessages->queue($record->fresh());
                        Notification::make()->title('Bulk message queued')->success()->send();
                        $this->refreshFormData([
                            'status', 'queued_at', 'completed_at',
                            'sent_count', 'failed_count', 'skipped_count', 'total_count', 'matched_count',
                        ]);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not send')
                            ->body(collect($e->errors())->flatten()->first())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('resend_failed')
                ->label('Re-send failed')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $record->failed_count > 0)
                ->requiresConfirmation()
                ->modalHeading('Re-send failed messages')
                ->modalDescription('Only recipients marked Failed will be queued again.')
                ->action(function (BulkMessageService $bulkMessages) use ($record): void {
                    try {
                        $bulkMessages->resendFailed($record->fresh());
                        Notification::make()->title('Failed recipients re-queued')->success()->send();
                        $this->refreshFormData([
                            'status', 'queued_at', 'completed_at',
                            'sent_count', 'failed_count', 'skipped_count', 'total_count', 'matched_count',
                        ]);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not re-send')
                            ->body(collect($e->errors())->flatten()->first())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
