<?php

namespace App\Filament\Resources\RevenueImports\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'payloadRows';

    protected static ?string $title = 'Import payload';

    /**
     * Keep row SMS / status actions available on the View page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

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
                TextColumn::make('error')->wrap()->toggleable()->limit(80),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TernaryFilter::make('needs_attention')
                    ->label('Needs attention')
                    ->queries(
                        true: fn ($query) => $query->where('status', '!=', RevenueImportRowStatus::Matched->value),
                        false: fn ($query) => $query->where('status', RevenueImportRowStatus::Matched->value),
                        blank: fn ($query) => $query,
                    ),
                SelectFilter::make('status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->reject(fn (RevenueImportRowStatus $s) => $s === RevenueImportRowStatus::Sent)
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
                    ->modalDescription('Only rows with status Ready (partner phone set) can be sent. Status becomes Sent after queueing.')
                    ->visible(function (RevenueImportRow $record): bool {
                        /** @var User|null $user */
                        $user = auth()->user();
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();

                        return $user
                            && app(RevenueImportService::class)->actorCanSend($user, $import)
                            && app(RevenueImportService::class)->rowCanSendSms($record->loadMissing('partner'), $import);
                    })
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        try {
                            $revenueImports->sendRowsViaBulkMessage($import->fresh(), [$record->id]);
                            Notification::make()
                                ->title('SMS queued')
                                ->body('Row status set to Sent.')
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
                                ->reject(fn (RevenueImportRowStatus $s) => $s === RevenueImportRowStatus::Sent)
                                ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText('Ready requires a master partner with a usable phone. Sent is set only by Send SMS.'),
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
                    ->fillForm(function (RevenueImportRow $record): array {
                        $record->loadMissing('partner');

                        return [
                            'revenue_partner_id' => $record->revenue_partner_id,
                            'partner_phone' => $record->partner?->phone,
                            'service_id' => $record->service_id,
                            'short_code' => $record->short_code,
                            'amount' => $record->amount,
                        ];
                    })
                    ->form([
                        Select::make('revenue_partner_id')
                            ->label('Partner')
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->live()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchRevenuePartners($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                if (! filled($value)) {
                                    return null;
                                }

                                return $this->revenuePartnerLabel((int) $value);
                            })
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (! filled($state)) {
                                    $set('partner_phone', null);

                                    return;
                                }

                                $partner = RevenuePartner::query()->find((int) $state);
                                if (! $partner) {
                                    $set('partner_phone', null);

                                    return;
                                }

                                $set('partner_phone', $partner->phone);
                                $set('service_id', $partner->service_id);
                                $set('short_code', $partner->short_code);
                            })
                            ->helperText('Search by partner name or Service ID. List is from Revenue Partners for this import’s AM (admins see all).'),
                        TextInput::make('partner_phone')
                            ->label('Phone')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Select a partner'),
                        TextInput::make('service_id')
                            ->label('Service ID')
                            ->required(fn ($get): bool => blank($get('revenue_partner_id')))
                            ->maxLength(64)
                            ->helperText('Used when no partner is selected.'),
                        TextInput::make('short_code')
                            ->label('Short code')
                            ->maxLength(64)
                            ->helperText('Optional. Used with Service ID when matching.'),
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
                Action::make('view_duplicate')
                    ->label('View duplicate')
                    ->icon('heroicon-o-eye')
                    ->color('danger')
                    ->visible(fn (RevenueImportRow $record): bool => $record->status === RevenueImportRowStatus::Duplicate)
                    ->modalHeading('Duplicate details')
                    ->modalDescription('Who already sent this partner, when, and for how much.')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->formWrapper(false)
                    ->schema(function (RevenueImportRow $record): array {
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        $service = app(RevenueImportService::class);
                        $summary = $service->duplicateRowSummary($record, $import);
                        $matches = $service->findDuplicateMatches($record, $import);

                        return [
                            TextEntry::make('duplicate_reason')
                                ->label('Why blocked')
                                ->state($summary['error'])
                                ->color('danger')
                                ->columnSpanFull(),
                            RepeatableEntry::make('current_row')
                                ->label('This payload row')
                                ->state([$summary])
                                ->table([
                                    TableColumn::make('Partner'),
                                    TableColumn::make('Service ID'),
                                    TableColumn::make('Short code'),
                                    TableColumn::make('Period'),
                                    TableColumn::make('Amount')->alignment(Alignment::End),
                                    TableColumn::make('Phone'),
                                    TableColumn::make('Status'),
                                ])
                                ->schema([
                                    TextEntry::make('partner_name')->placeholder('—'),
                                    TextEntry::make('service_id')->placeholder('—')->fontFamily(FontFamily::Mono),
                                    TextEntry::make('short_code')->placeholder('—'),
                                    TextEntry::make('period')->placeholder('—'),
                                    TextEntry::make('amount_label')->placeholder('—'),
                                    TextEntry::make('phone')->placeholder('—'),
                                    TextEntry::make('status')->badge()->color('danger'),
                                ])
                                ->columnSpanFull(),
                            RepeatableEntry::make('matches')
                                ->label('Matching sends')
                                ->state($matches)
                                ->placeholder('No matching prior or same-import rows were found.')
                                ->table([
                                    TableColumn::make('Source'),
                                    TableColumn::make('Import'),
                                    TableColumn::make('AM'),
                                    TableColumn::make('Sent by'),
                                    TableColumn::make('When'),
                                    TableColumn::make('Amount')->alignment(Alignment::End),
                                    TableColumn::make('Partner'),
                                    TableColumn::make('Service ID'),
                                    TableColumn::make('Phone'),
                                    TableColumn::make('Service'),
                                    TableColumn::make('Period'),
                                    TableColumn::make('Status'),
                                    TableColumn::make('Open')->hiddenHeaderLabel(),
                                ])
                                ->schema([
                                    TextEntry::make('source')->badge()->color('primary'),
                                    TextEntry::make('import_title')->placeholder('—'),
                                    TextEntry::make('am_name')->placeholder('—'),
                                    TextEntry::make('sent_by_name')->placeholder('—'),
                                    TextEntry::make('sent_at')->placeholder('—'),
                                    TextEntry::make('amount_label')->placeholder('—'),
                                    TextEntry::make('partner_name')->placeholder('—'),
                                    TextEntry::make('service_id')->placeholder('—')->fontFamily(FontFamily::Mono),
                                    TextEntry::make('phone')->placeholder('—'),
                                    TextEntry::make('catalog_service')->placeholder('—'),
                                    TextEntry::make('period')->placeholder('—'),
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('url')
                                        ->label('Open')
                                        ->formatStateUsing(fn (): string => 'Open')
                                        ->url(fn (?string $state): ?string => $state)
                                        ->openUrlInNewTab()
                                        ->color('primary'),
                                ])
                                ->columnSpanFull(),
                        ];
                    }),
                Action::make('open_partner')
                    ->label('Partner')
                    ->icon('heroicon-o-identification')
                    ->visible(fn (RevenueImportRow $record): bool => filled($record->revenue_partner_id))
                    ->url(fn (RevenueImportRow $record): ?string => $record->partner
                        ? RevenuePartnerResource::getUrl('view', ['record' => $record->partner])
                        : null),
                Action::make('delete_row')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete payload row')
                    ->modalDescription('Remove this row from the import. Sent rows cannot be deleted.')
                    ->visible(fn (RevenueImportRow $record): bool => $this->canDeleteRow($record))
                    ->action(function (RevenueImportRow $record, RevenueImportService $revenueImports): void {
                        /** @var User $user */
                        $user = auth()->user();
                        /** @var RevenueImport $import */
                        $import = $this->getOwnerRecord();
                        try {
                            $result = $revenueImports->deleteRows($import->fresh(), [$record->id], $user);
                            if ($result['deleted'] < 1) {
                                Notification::make()
                                    ->title('Could not delete')
                                    ->body($result['errors'][0] ?? 'Row could not be deleted.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            Notification::make()
                                ->title('Row deleted')
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not delete')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('send_sms')
                        ->label('Send SMS')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Send SMS for selected Ready rows')
                        ->modalDescription('Every selected row must have status Ready with a partner phone and not yet sent. Non-Ready rows will block the send.')
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
                                    ->reject(fn (RevenueImportRowStatus $s) => $s === RevenueImportRowStatus::Sent)
                                    ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                                    ->all())
                                ->required()
                                ->native(false)
                                ->helperText('Ready requires a master partner with a usable phone. Sent is set only by Send SMS.'),
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
                    BulkAction::make('delete_selected')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected payload rows')
                        ->modalDescription('Sent rows are skipped. Import counts refresh after delete.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => $this->importIsEditable())
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            /** @var User $user */
                            $user = auth()->user();
                            /** @var RevenueImport $import */
                            $import = $this->getOwnerRecord();
                            try {
                                $result = $revenueImports->deleteRows(
                                    $import->fresh(),
                                    $records->pluck('id')->all(),
                                    $user,
                                );
                                Notification::make()
                                    ->title($result['deleted'] > 0
                                        ? "Deleted {$result['deleted']} row(s)"
                                        : 'Nothing deleted')
                                    ->body(trim(implode(' ', array_filter([
                                        $result['skipped'] > 0 ? "{$result['skipped']} skipped (already sent)." : null,
                                        $result['errors'] !== [] ? implode(' ', array_slice($result['errors'], 0, 3)) : null,
                                    ]))) ?: null)
                                    ->color($result['deleted'] > 0 ? 'success' : 'warning')
                                    ->send();
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not delete rows')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->selectable()
            ->paginated([25, 50, 100]);
    }

    /**
     * Partners available for this import: AM’s list, or all for admins.
     *
     * @return array<int, string>
     */
    protected function searchRevenuePartners(string $search): array
    {
        $query = $this->revenuePartnersQuery();
        $term = trim($search);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('partner_name', 'ilike', $like)
                    ->orWhere('service_id', 'ilike', $like)
                    ->orWhere('short_code', 'ilike', $like);
            });
        }

        return $query
            ->orderBy('partner_name')
            ->orderBy('service_id')
            ->limit(50)
            ->get(['id', 'partner_name', 'service_id', 'phone', 'is_active'])
            ->mapWithKeys(fn (RevenuePartner $partner): array => [
                $partner->id => $this->formatRevenuePartnerOption($partner),
            ])
            ->all();
    }

    protected function revenuePartnerLabel(int $partnerId): ?string
    {
        $partner = RevenuePartner::query()->find($partnerId);

        return $partner ? $this->formatRevenuePartnerOption($partner) : null;
    }

    protected function formatRevenuePartnerOption(RevenuePartner $partner): string
    {
        $label = trim((string) $partner->partner_name) ?: 'Unnamed partner';
        $sid = filled($partner->service_id) ? (string) $partner->service_id : 'no Service ID';
        $notes = [];
        if (! $partner->is_active) {
            $notes[] = 'inactive';
        }
        if (! $partner->hasUsablePhone()) {
            $notes[] = 'no phone';
        }
        $suffix = $notes !== [] ? ' · '.implode(', ', $notes) : '';

        return "{$label} · {$sid}{$suffix}";
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\RevenuePartner>
     */
    protected function revenuePartnersQuery()
    {
        /** @var RevenueImport|null $import */
        $import = $this->getOwnerRecord();
        /** @var User|null $actor */
        $actor = auth()->user();

        $query = RevenuePartner::query()->where('is_active', true);

        // Admins see all. Covering AMs / owners see the import owner’s partners.
        if ($actor?->canAccessAllRevenue()) {
            return $query;
        }

        $ownerUserId = $import?->created_by_user_id ? (int) $import->created_by_user_id : null;
        if ($ownerUserId && $actor && AppSetting::canActForRevenueOwner($actor, $ownerUserId)) {
            return $query->where(function ($q) use ($ownerUserId): void {
                $q->where('created_by_user_id', $ownerUserId)
                    ->orWhereNull('created_by_user_id');
            });
        }

        if ($actor) {
            return $query->where('created_by_user_id', (int) $actor->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @deprecated Use searchRevenuePartners()
     *
     * @return array<int, string>
     */
    protected function revenuePartnerOptions(?int $includePartnerId = null): array
    {
        return $this->searchRevenuePartners('');
    }

    protected function canDeleteRow(RevenueImportRow $record): bool
    {
        return $this->canEditRow($record);
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

        // Admin / super_admin can edit any AM’s payload rows.
        if ($user->canAccessAllRevenue()) {
            return true;
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

        if (! RevenueImportResource::importIsEditable($import)) {
            return false;
        }

        if ($user->canAccessAllRevenue()) {
            return true;
        }

        return app(RevenueImportService::class)->actorCanManage($user, $import);
    }
}
