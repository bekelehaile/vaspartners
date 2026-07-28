<?php

namespace App\Filament\Resources\RevenueImports\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Import rows';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('needs_attention')
                    ->label('')
                    ->state(fn (RevenueImportRow $record): bool => $record->status !== RevenueImportRowStatus::Matched)
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (RevenueImportRow $record): string => $record->status instanceof RevenueImportRowStatus
                        ? $record->status->label()
                        : (string) $record->status),
                TextColumn::make('service_id')->label('Service ID')->searchable()->copyable(),
                TextColumn::make('short_code')->label('Short code')->placeholder('—')->toggleable()->searchable(),
                TextColumn::make('partner_name')->searchable()->wrap()->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Revenue')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                        ? $state
                        : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('partner.phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('SMS')
                    ->dateTime()
                    ->placeholder('Not sent')
                    ->toggleable(),
                TextColumn::make('error')->wrap()->toggleable()->limit(80),
            ])
            ->defaultSort('id')
            ->filters([
                TernaryFilter::make('needs_attention')
                    ->label('Needs attention')
                    ->queries(
                        true: fn ($query) => $query->where('status', '!=', RevenueImportRowStatus::Matched->value),
                        false: fn ($query) => $query->where('status', RevenueImportRowStatus::Matched->value),
                        blank: fn ($query) => $query,
                    ),
                TernaryFilter::make('sms_sent')
                    ->label('SMS sent')
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q->whereNotNull('sent_at')->orWhereNotNull('bulk_message_id')),
                        false: fn ($query) => $query->whereNull('sent_at')->whereNull('bulk_message_id'),
                        blank: fn ($query) => $query,
                    ),
                SelectFilter::make('status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('send_sms')
                    ->label('Send SMS')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Send SMS for this row')
                    ->modalDescription('Only Ready rows can be sent. Queues one SMS using the import template.')
                    ->visible(function (RevenueImportRow $record): bool {
                        /** @var User|null $user */
                        $user = auth()->user();
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();

                        return $user
                            && app(RevenueImportService::class)->actorCanSend($user, $import)
                            && app(RevenueImportService::class)->rowCanSendSms($record, $import);
                    })
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        try {
                            $revenueImports->sendRowsViaBulkMessage($import->fresh(), [$record->id]);
                            Notification::make()
                                ->title('SMS queued')
                                ->body($record->partner?->phone
                                    ? "Queued for {$record->partner->phone}"
                                    : 'Queued from Monthly Revenue.')
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not send SMS')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('set_status')
                    ->label('Set status')
                    ->icon('heroicon-o-flag')
                    ->color('gray')
                    ->visible(fn (RevenueImportRow $record): bool => $this->canEditRow($record))
                    ->fillForm(fn (RevenueImportRow $record): array => [
                        'status' => $record->status instanceof RevenueImportRowStatus
                            ? $record->status->value
                            : (string) $record->status,
                        'note' => null,
                    ])
                    ->form([
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(RevenueImportRowStatus::cases())
                                ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText('Ready requires a master partner with a usable phone.'),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->maxLength(255)
                            ->helperText('Optional. Stored as the row error for non-Ready statuses.'),
                    ])
                    ->action(function (RevenueImportRow $record, array $data, RevenueImportService $revenueImports): void {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        $status = RevenueImportRowStatus::from((string) $data['status']);
                        try {
                            $result = $revenueImports->setRowStatuses(
                                $import->fresh(),
                                [$record->id],
                                $status,
                                $data['note'] ?? null,
                            );
                            if ($result['updated'] > 0) {
                                Notification::make()
                                    ->title('Status updated')
                                    ->body($status->label())
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Could not set status')
                                    ->body($result['errors'][0] ?? 'Row was not updated.')
                                    ->danger()
                                    ->send();
                            }
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not set status')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('sync_phone')
                    ->label('Sync phone')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->visible(fn (RevenueImportRow $record): bool => $this->canEditRow($record)
                        && in_array($record->status, [
                            RevenueImportRowStatus::MissingPhone,
                            RevenueImportRowStatus::MissingPartner,
                        ], true))
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        try {
                            $result = $revenueImports->syncPhonesFromPartners($import->fresh(), [$record->id]);
                            $fresh = $record->fresh();
                            if ($result['synced'] > 0) {
                                Notification::make()
                                    ->title('Phone synced from partner')
                                    ->body($fresh?->partner?->phone
                                        ? "Phone {$fresh->partner->phone}"
                                        : 'Row is ready.')
                                    ->success()
                                    ->send();
                            } elseif ($result['still_missing'] > 0) {
                                Notification::make()
                                    ->title('Partner still has no usable phone')
                                    ->body('Set phone on the master partner (matched by Service ID or Short code), then try again.')
                                    ->warning()
                                    ->send();
                            } elseif ($result['unresolved'] > 0) {
                                Notification::make()
                                    ->title('No matching partner')
                                    ->body('No master partner found for this Service ID / Short code.')
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Could not sync phone')
                                    ->body($fresh?->error ?? 'Row was not updated.')
                                    ->warning()
                                    ->send();
                            }
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not sync phone')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('edit_row')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color(fn (RevenueImportRow $record): string => $record->status === RevenueImportRowStatus::Matched ? 'gray' : 'warning')
                    ->visible(fn (RevenueImportRow $record): bool => $this->canEditRow($record))
                    ->fillForm(fn (RevenueImportRow $record): array => [
                        'service_id' => $record->service_id,
                        'short_code' => $record->short_code,
                        'amount' => $record->amount,
                    ])
                    ->form([
                        TextInput::make('service_id')
                            ->label('Service ID')
                            ->required()
                            ->maxLength(64),
                        TextInput::make('short_code')
                            ->label('Short code')
                            ->maxLength(64)
                            ->helperText('Optional. Used with service ID when matching master.'),
                        TextInput::make('amount')
                            ->label('Revenue')
                            ->numeric()
                            ->required()
                            ->rule('gt:0'),
                    ])
                    ->action(function (RevenueImportRow $record, array $data, RevenueImportService $revenueImports): void {
                        /** @var User $user */
                        $user = auth()->user();
                        try {
                            $revenueImports->updateRow($record, $data, $user);
                            Notification::make()
                                ->title('Row updated')
                                ->body($record->fresh()->status instanceof RevenueImportRowStatus
                                    ? $record->fresh()->status->label()
                                    : 'Saved')
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not update row')
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
                    BulkAction::make('send_sms')
                        ->label('Send SMS')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Send SMS for selected Ready rows')
                        ->modalDescription('Every selected row must be Ready (partner phone set, not yet sent). Non-Ready rows will block the send.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(function (): bool {
                            /** @var User|null $user */
                            $user = auth()->user();
                            /** @var RevenueImport|null $import */
                            $import = $this->getOwnerRecord();

                            return $user && $import
                                && app(RevenueImportService::class)->actorCanSend($user, $import)
                                && app(RevenueImportService::class)->importCanSendSms($import);
                        })
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            /** @var RevenueImport $import */
                            $import = $this->getOwnerRecord();
                            try {
                                $campaign = $revenueImports->sendRowsViaBulkMessage(
                                    $import->fresh(),
                                    $records->pluck('id')->all(),
                                );
                                Notification::make()
                                    ->title('SMS queued')
                                    ->body($campaign->recipients()->count().' message(s) queued from Monthly Revenue.')
                                    ->success()
                                    ->send();
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not send SMS')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    BulkAction::make('set_status')
                        ->label('Set status')
                        ->icon('heroicon-o-flag')
                        ->color('gray')
                        ->form([
                            Select::make('status')
                                ->label('Status')
                                ->options(collect(RevenueImportRowStatus::cases())
                                    ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                                    ->all())
                                ->required()
                                ->native(false)
                                ->helperText('Ready requires a master partner with a usable phone.'),
                            Textarea::make('note')
                                ->label('Note')
                                ->rows(2)
                                ->maxLength(255)
                                ->helperText('Optional. Applied to non-Ready statuses.'),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => $this->importIsEditable())
                        ->action(function (Collection $records, array $data, RevenueImportService $revenueImports): void {
                            /** @var RevenueImport $import */
                            $import = $this->getOwnerRecord();
                            $status = RevenueImportRowStatus::from((string) $data['status']);
                            try {
                                $result = $revenueImports->setRowStatuses(
                                    $import->fresh(),
                                    $records->pluck('id')->all(),
                                    $status,
                                    $data['note'] ?? null,
                                );
                                Notification::make()
                                    ->title($result['updated'] > 0
                                        ? "Set {$status->label()} on {$result['updated']} row(s)"
                                        : 'No rows updated')
                                    ->body(trim(implode(' ', array_filter([
                                        $result['skipped'] > 0 ? "{$result['skipped']} skipped." : null,
                                        $result['errors'] !== [] ? implode(' ', array_slice($result['errors'], 0, 3)) : null,
                                    ]))) ?: null)
                                    ->color($result['updated'] > 0 ? 'success' : 'warning')
                                    ->send();
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not set status')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    BulkAction::make('sync_phones')
                        ->label('Sync phones from partners')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Sync phones from Revenue Partners')
                        ->modalDescription('Match selected rows by Service ID or Short code and mark ready when the master partner has a phone.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => $this->importIsEditable())
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            /** @var RevenueImport $import */
                            $import = $this->getOwnerRecord();
                            try {
                                $result = $revenueImports->syncPhonesFromPartners(
                                    $import->fresh(),
                                    $records->pluck('id')->all(),
                                );
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
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not sync phones')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function canEditRow(RevenueImportRow $record): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        /** @var RevenueImport|null $import */
        $import = $this->getOwnerRecord();
        if (! $user || ! $import) {
            return false;
        }

        if (! RevenueImportResource::importIsEditable($import) || $record->wasSent()) {
            return false;
        }

        return app(RevenueImportService::class)->actorCanManage($user, $import);
    }

    protected function importIsEditable(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        /** @var RevenueImport|null $import */
        $import = $this->getOwnerRecord();
        if (! $user || ! $import) {
            return false;
        }

        return RevenueImportResource::importIsEditable($import)
            && app(RevenueImportService::class)->actorCanManage($user, $import);
    }
}
