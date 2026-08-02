<?php

namespace App\Filament\Resources\BulkMessages\RelationManagers;

use App\Enums\BulkMessageRecipientStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\BulkMessageRecipient;
use App\Models\Company;
use App\Services\BulkMessageService;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Recipients';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Recipient')->schema([
                TextInput::make('company_name')
                    ->label('Company name')
                    ->maxLength(255),
                TextInput::make('company_tin')
                    ->label('TIN number')
                    ->maxLength(64),
                TextInput::make('phone_raw')
                    ->label('Phone')
                    ->required()
                    ->maxLength(32)
                    ->helperText('Ethio telecom mobile. Saved as last 9 digits for sending.'),
                Select::make('company_id')
                    ->label('Matched company')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Optional override. Leave blank to clear the company match.'),
                Select::make('status')
                    ->options(collect(BulkMessageRecipientStatus::cases())->mapWithKeys(
                        fn (BulkMessageRecipientStatus $s) => [$s->value => $s->label()]
                    ))
                    ->required()
                    ->native(false),
            ])->columns(2),
            Section::make('Message variables')->schema([
                TextInput::make('variables.period')->label('Period')->maxLength(120),
                TextInput::make('variables.service_type')->label('Service type')->maxLength(120),
                TextInput::make('variables.service_id')->label('Service ID')->maxLength(120),
                TextInput::make('variables.amount')->label('Amount')->maxLength(64),
            ])->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('row_number')->label('Row')->sortable(),
                TextColumn::make('company_name')
                    ->label('Company')
                    ->searchable()
                    ->url(fn (BulkMessageRecipient $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextColumn::make('company_tin')->label('TIN number')->toggleable(),
                TextColumn::make('phone_normalized')->label('Phone (last 9)')->searchable(),
                TextColumn::make('variables.period')->label('Period')->toggleable(),
                TextColumn::make('variables.service_type')->label('Service')->toggleable(),
                TextColumn::make('variables.service_id')->label('Service ID')->toggleable(),
                TextColumn::make('variables.amount')->label('Amount')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BulkMessageRecipientStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof BulkMessageRecipientStatus ? $state : BulkMessageRecipientStatus::tryFrom((string) $state)) {
                        BulkMessageRecipientStatus::Sent => 'success',
                        BulkMessageRecipientStatus::Failed => 'danger',
                        BulkMessageRecipientStatus::Skipped => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('attempts')->toggleable(),
                TextColumn::make('error')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('sent_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(BulkMessageRecipientStatus::cases())->mapWithKeys(
                    fn (BulkMessageRecipientStatus $s) => [$s->value => $s->label()]
                )),
            ])
            ->headerActions([
                Action::make('retryAllFailed')
                    ->label('Retry all failed')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => $this->getOwnerRecord()
                        ->recipients()
                        ->where('status', BulkMessageRecipientStatus::Failed->value)
                        ->exists())
                    ->requiresConfirmation()
                    ->modalHeading('Retry every failed SMS in this campaign?')
                    ->action(function (BulkMessageService $bulkMessages): void {
                        try {
                            $count = $bulkMessages->retryFailedRecipients($this->getOwnerRecord());
                            $this->getOwnerRecord()->refresh();
                            Notification::make()
                                ->title("Re-queued {$count} failed recipient(s)")
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not retry')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (BulkMessageRecipient $record): bool => in_array(
                        $record->status instanceof BulkMessageRecipientStatus
                            ? $record->status
                            : BulkMessageRecipientStatus::tryFrom((string) $record->status),
                        [BulkMessageRecipientStatus::Failed, BulkMessageRecipientStatus::Pending],
                        true,
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('Retry this SMS?')
                    ->modalDescription('This recipient will be queued again on the SMS worker.')
                    ->action(function (BulkMessageRecipient $record, BulkMessageService $bulkMessages): void {
                        try {
                            $bulkMessages->retryRecipient($record);
                            $this->getOwnerRecord()->refresh();
                            Notification::make()
                                ->title('Recipient re-queued')
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not retry')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $sms = app(SmsService::class);
                        $phone = trim((string) ($data['phone_raw'] ?? ''));
                        $data['phone_raw'] = $phone !== '' ? $phone : null;

                        if ($phone !== '' && $sms->ensurePhoneIsLocal($phone)) {
                            $normalized = $sms->normalizePhone($phone);
                            $data['phone_normalized'] = $normalized;

                            // If company was not explicitly chosen, try match by last-9.
                            if (blank($data['company_id'] ?? null)) {
                                $company = Company::query()
                                    ->whereRaw(
                                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                                        [$normalized]
                                    )
                                    ->first();
                                if ($company) {
                                    $data['company_id'] = $company->id;
                                    $data['company_name'] = $data['company_name'] ?: $company->name;
                                    $data['company_tin'] = $data['company_tin'] ?: $company->tin;
                                }
                            }
                        } elseif ($phone !== '') {
                            Notification::make()
                                ->title('Phone may not be a local mobile')
                                ->body('Saved anyway; sending may skip this recipient.')
                                ->warning()
                                ->send();
                            $data['phone_normalized'] = preg_replace('/\D+/', '', $phone);
                            $data['phone_normalized'] = $data['phone_normalized']
                                ? substr($data['phone_normalized'], -9)
                                : null;
                        } else {
                            $data['phone_normalized'] = null;
                        }

                        $vars = is_array($data['variables'] ?? null) ? $data['variables'] : [];
                        $data['variables'] = array_filter(
                            [
                                'period' => $vars['period'] ?? null,
                                'service_type' => $vars['service_type'] ?? null,
                                'service_id' => $vars['service_id'] ?? null,
                                'amount' => $vars['amount'] ?? null,
                                'company_name' => $data['company_name'] ?? null,
                            ],
                            fn ($v) => filled($v),
                        ) ?: null;

                        return $data;
                    })
                    ->after(function (): void {
                        $this->getOwnerRecord()->refreshCounts();
                    }),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->after(function (): void {
                        $this->getOwnerRecord()->refreshCounts();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retrySelected')
                        ->label('Retry selected failed')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry selected failed SMS?')
                        ->modalDescription('Only Failed rows in the selection will be re-queued.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, BulkMessageService $bulkMessages): void {
                            $ids = $records
                                ->filter(function (BulkMessageRecipient $record): bool {
                                    $status = $record->status instanceof BulkMessageRecipientStatus
                                        ? $record->status
                                        : BulkMessageRecipientStatus::tryFrom((string) $record->status);

                                    return $status === BulkMessageRecipientStatus::Failed;
                                })
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->all();

                            try {
                                $count = $bulkMessages->retryFailedRecipients(
                                    $this->getOwnerRecord(),
                                    $ids,
                                );
                                $this->getOwnerRecord()->refresh();
                                Notification::make()
                                    ->title("Re-queued {$count} failed recipient(s)")
                                    ->success()
                                    ->send();
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Could not retry')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->paginated([25, 50, 100]);
    }
}
