<?php

namespace App\Filament\Resources\RevenueImports\RelationManagers;

use App\Enums\BulkMessageRecipientStatus;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SentSmsRelationManager extends RelationManager
{
    protected static string $relationship = 'sentRows';

    protected static ?string $title = 'Sent SMS';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['partner', 'smsRecipient']))
            ->columns([
                TextColumn::make('service_id')->label('Service ID')->searchable()->copyable(),
                TextColumn::make('short_code')->label('Short code')->placeholder('—')->toggleable()->searchable(),
                TextColumn::make('partner_name')->label('Partner')->searchable()->wrap()->placeholder('—'),
                TextColumn::make('partner.phone')
                    ->label('Phone')
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Revenue')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('smsRecipient.status')
                    ->label('SMS status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BulkMessageRecipientStatus
                        ? $state->label()
                        : (BulkMessageRecipientStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state) => match ($state instanceof BulkMessageRecipientStatus
                        ? $state
                        : BulkMessageRecipientStatus::tryFrom((string) $state)) {
                        BulkMessageRecipientStatus::Sent => 'success',
                        BulkMessageRecipientStatus::Pending => 'info',
                        BulkMessageRecipientStatus::Failed => 'danger',
                        BulkMessageRecipientStatus::Skipped => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('smsRecipient.attempts')
                    ->label('Attempts')
                    ->toggleable(),
                TextColumn::make('smsRecipient.sent_at')
                    ->label('Delivered at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('smsRecipient.error')
                    ->label('SMS error')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('Queued at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                SelectFilter::make('sms_status')
                    ->label('SMS status')
                    ->options(collect(BulkMessageRecipientStatus::cases())
                        ->mapWithKeys(fn (BulkMessageRecipientStatus $s) => [$s->value => $s->label()])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas('smsRecipient', fn (Builder $q) => $q->where('status', $value));
                    }),
            ])
            ->recordActions([
                Action::make('retry_sms')
                    ->label('Retry SMS')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retry failed SMS')
                    ->modalDescription('Re-queues this message. Double-send of successful SMS is still blocked.')
                    ->visible(function (RevenueImportRow $record): bool {
                        /** @var User|null $user */
                        $user = auth()->user();
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();

                        return $user
                            && app(RevenueImportService::class)->actorCanSend($user, $import)
                            && app(RevenueImportService::class)->rowCanRetrySms($record->loadMissing('smsRecipient'));
                    })
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        try {
                            $revenueImports->retryFailedSms($import->fresh(), [$record->id]);
                            Notification::make()
                                ->title('SMS retry queued')
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not retry SMS')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('open_partner')
                    ->label('Partner')
                    ->icon('heroicon-o-identification')
                    ->visible(fn (RevenueImportRow $record): bool => filled($record->revenue_partner_id))
                    ->url(fn (RevenueImportRow $record): ?string => $record->partner
                        ? RevenuePartnerResource::getUrl('view', ['record' => $record->partner])
                        : null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retry_failed_sms')
                        ->label('Retry failed SMS')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry failed SMS for selected rows')
                        ->modalDescription('Only Failed / Skipped SMS rows will be re-queued.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(function (): bool {
                            /** @var User|null $user */
                            $user = auth()->user();
                            /** @var RevenueImport|null $import */
                            $import = $this->getOwnerRecord();

                            return $user && $import
                                && app(RevenueImportService::class)->actorCanSend($user, $import);
                        })
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            /** @var RevenueImport $import */
                            $import = $this->getOwnerRecord();
                            try {
                                $result = $revenueImports->retryFailedSms(
                                    $import->fresh(),
                                    $records->pluck('id')->all(),
                                );
                                Notification::make()
                                    ->title($result['retried'] > 0
                                        ? "Retried {$result['retried']} SMS"
                                        : 'No SMS retried')
                                    ->body(trim(implode(' ', array_filter([
                                        $result['skipped'] > 0 ? "{$result['skipped']} skipped." : null,
                                        $result['errors'] !== [] ? implode(' ', array_slice($result['errors'], 0, 3)) : null,
                                    ]))) ?: null)
                                    ->color($result['retried'] > 0 ? 'success' : 'warning')
                                    ->send();
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not retry SMS')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No SMS queued yet')
            ->emptyStateDescription('Send Ready rows from Import payload; delivery and retries appear here.');
    }
}
