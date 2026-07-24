<?php

namespace App\Filament\Resources\BulkMessages;

use App\Enums\BulkMessageStatus;
use App\Filament\Resources\BulkMessages\Pages\ImportBulkMessage;
use App\Filament\Resources\BulkMessages\Pages\ListBulkMessages;
use App\Filament\Resources\BulkMessages\Pages\ViewBulkMessage;
use App\Models\BulkMessage;
use App\Services\BulkMessageService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BulkMessageResource extends Resource
{
    protected static ?string $model = BulkMessage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Bulk messages';

    protected static ?string $modelLabel = 'Bulk message';

    protected static ?string $pluralModelLabel = 'Bulk messages';

    protected static ?string $slug = 'bulk-messages';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bulk message')->schema([
                TextEntry::make('title'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BulkMessageStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof BulkMessageStatus ? $state : BulkMessageStatus::tryFrom((string) $state)) {
                        BulkMessageStatus::Completed => 'success',
                        BulkMessageStatus::Failed => 'danger',
                        BulkMessageStatus::Processing, BulkMessageStatus::Queued => 'info',
                        default => 'gray',
                    }),
                TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                TextEntry::make('source_filename')->label('Imported file')->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('queued_at')->dateTime()->placeholder('—'),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                TextEntry::make('message')->columnSpanFull(),
            ])->columns(2),
            Section::make('Counts')->schema([
                TextEntry::make('total_count')->label('Total rows'),
                TextEntry::make('matched_count')->label('Matched companies'),
                TextEntry::make('sent_count')->label('Sent'),
                TextEntry::make('failed_count')->label('Failed'),
                TextEntry::make('skipped_count')->label('Skipped'),
            ])->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BulkMessageStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof BulkMessageStatus ? $state : BulkMessageStatus::tryFrom((string) $state)) {
                        BulkMessageStatus::Completed => 'success',
                        BulkMessageStatus::Failed => 'danger',
                        BulkMessageStatus::Processing, BulkMessageStatus::Queued => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('total_count')->label('Total'),
                TextColumn::make('sent_count')->label('Sent'),
                TextColumn::make('failed_count')->label('Failed'),
                TextColumn::make('skipped_count')->label('Skipped')->toggleable(),
                TextColumn::make('creator.name')->label('By')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->url(fn (BulkMessage $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (BulkMessage $record): bool => in_array($record->status, [
                        BulkMessageStatus::Draft,
                        BulkMessageStatus::Completed,
                        BulkMessageStatus::Failed,
                    ], true))
                    ->requiresConfirmation()
                    ->modalHeading('Send bulk message')
                    ->modalDescription('Pending and failed recipients will be queued to the SMS gateway.')
                    ->action(function (BulkMessage $record, BulkMessageService $bulkMessages): void {
                        try {
                            $bulkMessages->queue($record);
                            Notification::make()->title('Bulk message queued')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not send')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('resend_failed')
                    ->label('Re-send failed')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (BulkMessage $record): bool => $record->failed_count > 0
                        && in_array($record->status, [
                            BulkMessageStatus::Completed,
                            BulkMessageStatus::Failed,
                            BulkMessageStatus::Draft,
                        ], true))
                    ->requiresConfirmation()
                    ->action(function (BulkMessage $record, BulkMessageService $bulkMessages): void {
                        try {
                            $bulkMessages->resendFailed($record);
                            Notification::make()->title('Failed recipients re-queued')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not re-send')
                                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBulkMessages::route('/'),
            'import' => ImportBulkMessage::route('/import'),
            'view' => ViewBulkMessage::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // Creation is only via the dedicated Import page.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
