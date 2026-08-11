<?php

namespace App\Filament\Resources\RevenuePartners\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Models\RevenueImportRow;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MonthlyRevenueRelationManager extends RelationManager
{
    protected static string $relationship = 'importRows';

    protected static ?string $title = 'Monthly revenue';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import.period')
                    ->label('Period')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('vasService.name')
                    ->label('Catalog service')
                    ->toggleable(),
                TextColumn::make('import.title')
                    ->label('Import')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('import.status')
                    ->label('Import status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Row status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                        ? $state
                        : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('import.imported_at')
                    ->label('Imported')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('error')->wrap()->toggleable(isToggledHiddenByDefault: true)->limit(80),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Row status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
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
                        $import = $record->import;

                        return $user
                            && $import
                            && app(RevenueImportService::class)->actorCanSend($user, $import)
                            && app(RevenueImportService::class)->rowCanRetrySms($record->loadMissing('smsRecipient'));
                    })
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        $import = $record->import;
                        if (! $import) {
                            return;
                        }
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
                Action::make('open_import')
                    ->label('Open import')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn (RevenueImportRow $record): ?string => $record->import
                        ? RevenueImportResource::getUrl('view', ['record' => $record->import])
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
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            $retried = 0;
                            $skipped = 0;
                            $errors = [];
                            foreach ($records->groupBy('import_id') as $group) {
                                $import = $group->first()->import;
                                if (! $import) {
                                    $skipped += $group->count();
                                    continue;
                                }
                                try {
                                    $result = $revenueImports->retryFailedSms(
                                        $import->fresh(),
                                        $group->pluck('id')->all(),
                                    );
                                    $retried += $result['retried'];
                                    $skipped += $result['skipped'];
                                    $errors = array_merge($errors, $result['errors']);
                                } catch (ValidationException $e) {
                                    $skipped += $group->count();
                                    $errors[] = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                                }
                            }
                            Notification::make()
                                ->title($retried > 0 ? "Retried {$retried} SMS" : 'No SMS retried')
                                ->body(trim(implode(' ', array_filter([
                                    $skipped > 0 ? "{$skipped} skipped." : null,
                                    $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : null,
                                ]))) ?: null)
                                ->color($retried > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                ]),
            ])
            ->paginated([25, 50, 100]);
    }
}
