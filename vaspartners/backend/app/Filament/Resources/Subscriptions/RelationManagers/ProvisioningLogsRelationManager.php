<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\SubscriptionProvisioningLog;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProvisioningLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'provisioningLogs';

    protected static ?string $title = 'Provisioning logs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->provisioningLogs()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Activation, renewal, deactivation, and uptime status changes for this service.')
            ->modifyQueryUsing(fn ($query) => $query->with(['ticket', 'actor']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionProvisioningLog $record): string => $record->eventLabel())
                    ->color(fn (SubscriptionProvisioningLog $record): string => match ($record->event) {
                        'activated', 'renewed' => 'success',
                        'pending_renewal', 'operational_status_changed', 'contract_details_updated' => 'warning',
                        'terminated', 'closed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('from_status')
                    ->label('From')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('to_status')
                    ->label('To')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ticket.tt_number')
                    ->label('Request')
                    ->placeholder('—')
                    ->url(fn (SubscriptionProvisioningLog $record): ?string => $record->ticket
                        ? TicketResource::getUrl('view', ['record' => $record->ticket])
                        : null)
                    ->color('primary'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->tooltip(fn (SubscriptionProvisioningLog $record): ?string => mb_strlen((string) $record->note) > 80
                        ? 'Open View for the full note'
                        : null),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (SubscriptionProvisioningLog $record): string => sprintf(
                        '%s · %s',
                        $record->eventLabel(),
                        $record->created_at?->format('M j, Y H:i') ?? '—',
                    ))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        TextInput::make('event')
                            ->label('Event')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('request')
                            ->label('Request')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('by')
                            ->label('By')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('from_status')
                            ->label('From')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('to_status')
                            ->label('To')
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
                    ->fillForm(function (SubscriptionProvisioningLog $record): array {
                        $actor = $record->actor;
                        $meta = $record->meta;

                        return [
                            'event' => $record->eventLabel(),
                            'request' => $record->ticket?->tt_number ?: '—',
                            'by' => $actor?->name
                                ?? ($actor ? class_basename($actor::class).' #'.$actor->getKey() : 'System'),
                            'from_status' => $record->from_status ?: '—',
                            'to_status' => $record->to_status ?: '—',
                            'note' => filled($record->note) ? (string) $record->note : '—',
                            'meta' => is_array($meta) && $meta !== []
                                ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—',
                        ];
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->paginated([10, 25, 50]);
    }
}
