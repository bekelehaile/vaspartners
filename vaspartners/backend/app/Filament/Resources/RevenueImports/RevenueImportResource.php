<?php

namespace App\Filament\Resources\RevenueImports;

use App\Enums\RevenueImportStatus;
use App\Filament\Imports\MonthlyRevenueImporter;
use App\Filament\Resources\RevenueImports\Pages\EditRevenueImport;
use App\Filament\Resources\RevenueImports\Pages\ListRevenueImports;
use App\Filament\Resources\RevenueImports\Pages\ViewRevenueImport;
use App\Filament\Resources\RevenueImports\RelationManagers\RowsRelationManager;
use App\Models\RevenueImport;
use App\Models\User;
use App\Services\BulkMessageService;
use App\Services\RevenueImportService;
use App\Support\RevenueCatalogServices;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RevenueImportResource extends Resource
{
    protected static ?string $model = RevenueImport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Monthly revenue';

    protected static ?string $modelLabel = 'Monthly revenue';

    protected static ?string $pluralModelLabel = 'Monthly revenue';

    protected static ?string $slug = 'monthly-revenue';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import')->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('period')
                    ->label('Month')
                    ->options(fn (): array => MonthlyRevenueImporter::monthOptions())
                    ->required()
                    ->searchable()
                    ->native(false),
                Select::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options())
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Used for SMS {service_type} wording.'),
                Textarea::make('message_template')
                    ->label('SMS template')
                    ->rows(4)
                    ->required()
                    ->maxLength(640)
                    ->default(BulkMessageService::DEFAULT_MESSAGE)
                    ->helperText('{company_name} {period} {service_type} {service_id} {amount}')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import')->schema([
                TextEntry::make('title'),
                TextEntry::make('period')->label('Month'),
                TextEntry::make('vasService.name')->label('Catalog service'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextEntry::make('creator.name')->label('Imported by')->placeholder('—'),
                TextEntry::make('imported_at')->label('Imported at')->dateTime()->placeholder('—'),
                TextEntry::make('sender.name')->label('Sent by')->placeholder('—'),
                TextEntry::make('sent_at')->label('Sent at')->dateTime()->placeholder('—'),
                TextEntry::make('source_filename')->label('CSV file')->placeholder('—'),
                TextEntry::make('message_template')->label('SMS template')->columnSpanFull(),
            ])->columns(2),
            Section::make('Row counts')->schema([
                TextEntry::make('total_count')->label('Total'),
                TextEntry::make('matched_count')->label('Ready'),
                TextEntry::make('missing_partner_count')->label('Unresolved'),
                TextEntry::make('missing_phone_count')->label('Missing phone'),
                TextEntry::make('invalid_count')->label('Invalid / duplicate'),
            ])->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('period')->label('Month')->sortable(),
                TextColumn::make('vasService.name')->label('Catalog service')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('creator.name')->label('Imported by')->toggleable(),
                TextColumn::make('imported_at')->dateTime()->sortable(),
                TextColumn::make('sender.name')->label('Sent by')->toggleable(),
                TextColumn::make('sent_at')->dateTime()->toggleable(),
                TextColumn::make('matched_count')->label('Ready'),
                TextColumn::make('missing_partner_count')->label('Unresolved')->toggleable(),
                TextColumn::make('missing_phone_count')->label('Missing phone')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options()),
                SelectFilter::make('status')
                    ->options(collect(RevenueImportStatus::cases())
                        ->mapWithKeys(fn (RevenueImportStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (RevenueImport $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (RevenueImport $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (RevenueImport $record): bool => static::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('set_status')
                        ->label('Set status')
                        ->icon('heroicon-o-flag')
                        ->color('gray')
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
                                ->helperText('Ready only if all rows are fixed. Already-sent imports are skipped.'),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, RevenueImportService $revenueImports): void {
                            $status = RevenueImportStatus::from((string) $data['status']);
                            $done = 0;
                            $skipped = 0;
                            $errors = [];
                            foreach ($records as $import) {
                                if (! $import instanceof RevenueImport) {
                                    continue;
                                }
                                $import = $import->fresh();
                                if (! $import || ! static::importIsEditable($import)) {
                                    $skipped++;

                                    continue;
                                }
                                try {
                                    $revenueImports->setImportStatus($import, $status);
                                    $done++;
                                } catch (ValidationException $e) {
                                    $skipped++;
                                    $errors[] = ($import->title ?: 'Import').': '
                                        .(collect($e->errors())->flatten()->first() ?? $e->getMessage());
                                }
                            }
                            Notification::make()
                                ->title($done > 0
                                    ? "Set {$status->label()} on {$done} import(s)"
                                    : 'No imports updated')
                                ->body(trim(implode(' ', array_filter([
                                    $skipped > 0 ? "{$skipped} skipped." : null,
                                    $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : null,
                                ]))) ?: null)
                                ->color($done > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('rematch')
                        ->label('Rematch selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Rematch selected imports')
                        ->modalDescription('Re-check each selected import against the partner master list. Already-sent imports are skipped.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            $done = 0;
                            $skipped = 0;
                            foreach ($records as $import) {
                                if (! $import instanceof RevenueImport) {
                                    continue;
                                }
                                $import = $import->fresh();
                                if (! $import || ! static::importIsEditable($import)) {
                                    $skipped++;

                                    continue;
                                }
                                $revenueImports->rematch($import);
                                $done++;
                            }
                            Notification::make()
                                ->title($done > 0 ? "Rematched {$done} import(s)" : 'Nothing rematched')
                                ->body($skipped > 0 ? "{$skipped} skipped (already sent or locked)." : null)
                                ->color($done > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('register_missing')
                        ->label('Register missing partners')
                        ->icon('heroicon-o-user-plus')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Register missing partners')
                        ->modalDescription('Create master partners for unresolved rows on the selected imports. Already-sent imports are skipped.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            $created = 0;
                            $touched = 0;
                            $skipped = 0;
                            foreach ($records as $import) {
                                if (! $import instanceof RevenueImport) {
                                    continue;
                                }
                                $import = $import->fresh();
                                if (! $import || ! static::importIsEditable($import) || $import->missing_partner_count < 1) {
                                    $skipped++;

                                    continue;
                                }
                                $created += $revenueImports->registerMissingPartners($import);
                                $touched++;
                            }
                            Notification::make()
                                ->title($touched > 0
                                    ? "Registered on {$touched} import(s) ({$created} new partner(s))"
                                    : 'No partners registered')
                                ->body($skipped > 0 ? "{$skipped} skipped (locked, sent, or no unresolved rows)." : null)
                                ->color($touched > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('send_sms')
                        ->label('Send SMS')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Send SMS for selected imports')
                        ->modalDescription('Only imports with status Ready to send (and unsent Ready rows) will be queued.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, RevenueImportService $revenueImports): void {
                            /** @var User|null $user */
                            $user = auth()->user();
                            $queued = 0;
                            $skipped = 0;
                            $errors = [];

                            foreach ($records as $import) {
                                if (! $import instanceof RevenueImport) {
                                    continue;
                                }
                                $import = $import->fresh();
                                if (! $import || ! $user || ! $revenueImports->actorCanSend($user, $import)) {
                                    $skipped++;

                                    continue;
                                }
                                if (! $revenueImports->importCanSendSms($import)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $revenueImports->sendViaBulkMessage($import);
                                    $queued++;
                                } catch (ValidationException $e) {
                                    $skipped++;
                                    $errors[] = ($import->title ?: 'Import').': '
                                        .(collect($e->errors())->flatten()->first() ?? $e->getMessage());
                                }
                            }

                            Notification::make()
                                ->title($queued > 0 ? "Queued SMS for {$queued} import(s)" : 'No SMS queued')
                                ->body(trim(implode(' ', array_filter([
                                    $skipped > 0 ? "{$skipped} skipped." : null,
                                    $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : null,
                                ]))) ?: null)
                                ->color($queued > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function importIsEditable(RevenueImport $import): bool
    {
        return ! in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true);
    }

    public static function getRelations(): array
    {
        return [
            RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenueImports::route('/'),
            'view' => ViewRevenueImport::route('/{record}'),
            'edit' => EditRevenueImport::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('vasService');
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllRevenue()) {
            return $query;
        }

        return $query->where('created_by_user_id', $user->id);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof RevenueImport) {
            return false;
        }

        if (! static::importIsEditable($record)) {
            return false;
        }

        /** @var User|null $user */
        $user = auth()->user();

        return $user?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
