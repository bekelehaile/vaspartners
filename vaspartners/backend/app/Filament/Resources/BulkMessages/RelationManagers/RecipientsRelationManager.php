<?php

namespace App\Filament\Resources\BulkMessages\RelationManagers;

use App\Enums\BulkMessageRecipientStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\BulkMessageRecipient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Recipients';

    public function isReadOnly(): bool
    {
        return true;
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
                TextColumn::make('company_tin')->label('TIN')->toggleable(),
                TextColumn::make('phone_normalized')->label('Phone (last 9)')->searchable(),
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
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')->options(collect(BulkMessageRecipientStatus::cases())->mapWithKeys(
                    fn (BulkMessageRecipientStatus $s) => [$s->value => $s->label()]
                )),
            ])
            ->paginated([25, 50, 100]);
    }
}
