<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Enums\RevenueImportStatus;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewRevenueImport extends ViewRecord
{
    protected static string $resource = RevenueImportResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->syncStatus();
    }

    public function rendering(): void
    {
        $this->syncStatus();
    }

    protected function syncStatus(): void
    {
        /** @var RevenueImport $import */
        $import = $this->getRecord();
        app(RevenueImportService::class)->syncSendStatus($import->fresh());
        $this->refreshFormData(['status', 'sent_at', 'sent_by_user_id']);
    }

    public function getPollingInterval(): ?string
    {
        return $this->getRecord()->status === RevenueImportStatus::Sending ? '3s' : null;
    }

    protected function getHeaderActions(): array
    {
        /** @var RevenueImport $record */
        $record = $this->getRecord();
        /** @var User|null $user */
        $user = auth()->user();
        $canSend = $user && app(RevenueImportService::class)->actorCanSend($user, $record);

        return [
            EditAction::make()
                ->url(fn (): string => RevenueImportResource::getUrl('edit', ['record' => $record]))
                ->visible(fn (): bool => RevenueImportResource::canEdit($record->fresh() ?? $record)),
            Action::make('register_missing')
                ->label('Register missing partners')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(fn (): bool => $this->importIsEditable($record)
                    && $record->fresh()->missing_partner_count > 0)
                ->requiresConfirmation()
                ->action(function (RevenueImportService $revenueImports) use ($record): void {
                    $created = $revenueImports->registerMissingPartners($record->fresh());
                    Notification::make()
                        ->title('Partners registered')
                        ->body("{$created} new master record(s). Set phones, then Rematch / Send.")
                        ->success()
                        ->send();
                    $this->refreshFormData([
                        'status', 'matched_count', 'missing_partner_count', 'missing_phone_count', 'invalid_count',
                    ]);
                }),
            Action::make('open_partners')
                ->label('Master list')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->url(RevenuePartnerResource::getUrl('index')),
            Action::make('rematch')
                ->label('Rematch')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->importIsEditable($record))
                ->action(function (RevenueImportService $revenueImports) use ($record): void {
                    $revenueImports->rematch($record->fresh());
                    Notification::make()->title('Rematched against your partner list')->success()->send();
                    $this->refreshFormData([
                        'status', 'matched_count', 'missing_partner_count', 'missing_phone_count', 'invalid_count',
                    ]);
                }),
            Action::make('send')
                ->label('Send SMS')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => $canSend
                    && $this->importIsEditable($record)
                    && $record->fresh()->matched_count > 0
                    && $record->fresh()->missing_partner_count === 0
                    && $record->fresh()->missing_phone_count === 0
                    && $record->fresh()->invalid_count === 0)
                ->requiresConfirmation()
                ->modalHeading('Send bulk SMS for this import')
                ->modalDescription(fn (): string => sprintf(
                    'Queue %d ready row(s). Double sending for the same partner + month is blocked.',
                    $record->fresh()->matched_count,
                ))
                ->action(function (RevenueImportService $revenueImports) use ($record): void {
                    try {
                        $campaign = $revenueImports->sendViaBulkMessage($record->fresh());
                        Notification::make()->title('SMS queued')->success()->send();
                        $this->redirect(BulkMessageResource::getUrl('view', ['record' => $campaign]));
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not send')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function importIsEditable(RevenueImport $record): bool
    {
        $fresh = $record->fresh();

        return $fresh && RevenueImportResource::importIsEditable($fresh);
    }
}
