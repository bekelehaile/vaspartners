<?php

namespace App\Filament\Resources\RevenueImports\RelationManagers;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\User;
use App\Services\RevenueImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Import rows';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                /** @var User|null $user */
                $user = auth()->user();
                if (! $user || $user->canAccessAllRevenue()) {
                    return $query;
                }
                $families = $user->managedRevenueFamilyValues();

                return $families === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('service_family', $families);
            })
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
                TextColumn::make('service_type')->toggleable()->placeholder('—'),
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
            ->defaultSort('id')
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
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('service_family')
                    ->label('Family')
                    ->options(\App\Enums\RevenueServiceFamily::options()),
            ])
            ->recordActions([
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

        if (in_array($import->status, [RevenueImportStatus::Sending, RevenueImportStatus::Completed], true)) {
            return false;
        }

        return app(RevenueImportService::class)->actorCanManage($user, $import);
    }
}
