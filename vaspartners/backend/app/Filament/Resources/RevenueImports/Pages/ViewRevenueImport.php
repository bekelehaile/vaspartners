<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
            DeleteAction::make()
                ->visible(fn (): bool => RevenueImportResource::canDelete($record->fresh() ?? $record))
                ->modalHeading('Delete monthly revenue import')
                ->modalDescription('Deletes this import and its payload rows. Only allowed when no SMS has been queued or sent.')
                ->successRedirectUrl(RevenueImportResource::getUrl('index')),
            Action::make('set_status')
                ->label('Set status')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->visible(fn (): bool => $this->importIsEditable($record))
                ->fillForm(fn (): array => [
                    'status' => ($record->fresh()->status instanceof RevenueImportStatus
                        ? $record->fresh()->status->value
                        : (string) $record->fresh()->status),
                ])
                ->form([
                    Select::make('status')
                        ->label('Import status')
                        ->options(collect([
                            RevenueImportStatus::Draft,
                            RevenueImportStatus::Reviewing,
                            RevenueImportStatus::Ready,
                            RevenueImportStatus::Failed,
                        ])->mapWithKeys(fn (RevenueImportStatus $s) => [$s->value => $s->label()])->all())
                        ->required()
                        ->native(false)
                        ->helperText('Ready only if all rows are fixed. Sending / Completed are set when SMS is queued.'),
                ])
                ->action(function (array $data, RevenueImportService $revenueImports) use ($record): void {
                    try {
                        $status = RevenueImportStatus::from((string) $data['status']);
                        $revenueImports->setImportStatus($record->fresh(), $status);
                        Notification::make()
                            ->title('Import status updated')
                            ->body($status->label())
                            ->success()
                            ->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not set status')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
            Action::make('sync_phones')
                ->label('Sync phones')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('warning')
                ->visible(fn (): bool => $this->importIsEditable($record)
                    && $record->fresh()->missing_phone_count > 0)
                ->requiresConfirmation()
                ->modalHeading('Sync phones from Revenue Partners')
                ->modalDescription('Re-check missing-phone rows by Service ID or Short code. Rows become ready when the master partner has a usable phone.')
                ->action(function (RevenueImportService $revenueImports) use ($record): void {
                    try {
                        $result = $revenueImports->syncPhonesFromPartners($record->fresh());
                        Notification::make()
                            ->title($result['synced'] > 0
                                ? "Synced phone for {$result['synced']} row(s)"
                                : 'No phones synced')
                            ->body(collect([
                                $result['still_missing'] > 0 ? "{$result['still_missing']} still missing phone on partner" : null,
                                $result['unresolved'] > 0 ? "{$result['unresolved']} unresolved (no partner)" : null,
                            ])->filter()->implode('. ') ?: null)
                            ->color($result['synced'] > 0 ? 'success' : 'warning')
                            ->send();
                        $this->refreshFormData([
                            'status', 'matched_count', 'missing_partner_count', 'missing_phone_count', 'invalid_count',
                        ]);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not sync phones')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
                    && app(RevenueImportService::class)->importCanSendSms($record->fresh()))
                ->requiresConfirmation()
                ->modalHeading('Send SMS for Ready rows')
                ->modalDescription(fn (): string => sprintf(
                    'Queue %d Ready row(s) that still need SMS. Other unresolved rows can stay for later.',
                    app(RevenueImportService::class)->unsentReadyCount($record->fresh()),
                ))
                ->action(function (RevenueImportService $revenueImports) use ($record): void {
                    try {
                        $campaign = $revenueImports->sendViaBulkMessage($record->fresh());
                        $count = $campaign->recipients()->count();
                        Notification::make()
                            ->title('SMS queued')
                            ->body("{$count} message(s) queued from Monthly Revenue.")
                            ->success()
                            ->send();
                        $this->refreshFormData([
                            'status', 'matched_count', 'missing_partner_count', 'missing_phone_count', 'invalid_count', 'sent_at',
                        ]);
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
