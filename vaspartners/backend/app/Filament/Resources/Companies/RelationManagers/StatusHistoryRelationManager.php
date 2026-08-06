<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyStatusHistory;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->statusHistories()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('ERCA TIN number confirmation and Active changes.')
            ->modifyQueryUsing(fn ($query) => $query->with(['actorUser', 'actorContact']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actor')
                    ->label('By')
                    ->state(function (CompanyStatusHistory $record): string {
                        if ($record->actorUser) {
                            return $record->actorUser->name
                                ?: ($record->actorUser->email ?? 'Staff #'.$record->actor_user_id);
                        }
                        if ($record->actorContact) {
                            return ($record->actorContact->name ?? 'Partner')
                                .' (partner)';
                        }

                        return 'System / ERCA';
                    }),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (CompanyStatusHistory $record): string => $record->actionLabel())
                    ->color(fn (CompanyStatusHistory $record): string => match ($record->action) {
                        'approved', 'tin_validated', 'activated' => 'success',
                        'rejected', 'deactivated' => 'danger',
                        'tin_cleared' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                IconColumn::make('tin_validated')
                    ->label('TIN number verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable()
                    ->tooltip(fn (CompanyStatusHistory $record): ?string => mb_strlen((string) $record->note) > 80
                        ? 'Open View for the full note'
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (CompanyStatusHistory $record): string => sprintf(
                        '%s · %s',
                        $record->actionLabel(),
                        $record->created_at?->format('M j, Y H:i') ?? '—',
                    ))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        TextInput::make('action')
                            ->label('Action')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('by')
                            ->label('By')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('is_active')
                            ->label('Active')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('tin_validated')
                            ->label('TIN verified')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(8)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Textarea::make('meta')
                            ->label('Details')
                            ->rows(8)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->fillForm(function (CompanyStatusHistory $record): array {
                        if ($record->actorUser) {
                            $by = $record->actorUser->name
                                ?: ($record->actorUser->email ?? 'Staff #'.$record->actor_user_id);
                        } elseif ($record->actorContact) {
                            $by = ($record->actorContact->name ?? 'Partner').' (partner)';
                        } else {
                            $by = 'System / ERCA';
                        }

                        $meta = $record->meta;

                        return [
                            'action' => $record->actionLabel(),
                            'by' => $by,
                            'is_active' => $record->is_active ? 'Yes' : 'No',
                            'tin_validated' => $record->tin_validated ? 'Yes' : 'No',
                            'note' => filled($record->note) ? (string) $record->note : '—',
                            'meta' => is_array($meta) && $meta !== []
                                ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—',
                        ];
                    }),
            ])
            ->toolbarActions([]);
    }
}
