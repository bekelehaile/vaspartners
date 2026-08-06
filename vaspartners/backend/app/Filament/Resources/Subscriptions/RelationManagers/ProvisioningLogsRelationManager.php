<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Models\SubscriptionProvisioningLog;
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
                    ->placeholder('—'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
