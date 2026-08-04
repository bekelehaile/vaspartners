<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyMembershipAuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MembershipAuditRelationManager extends RelationManager
{
    protected static string $relationship = 'membershipAuditLogs';

    protected static ?string $title = 'Permission audit';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->membershipAuditLogs()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Member permission and access changes for this company.')
            ->modifyQueryUsing(fn ($query) => $query->with(['memberContact', 'actorUser', 'actorContact']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('memberContact.name')
                    ->label('Member')
                    ->placeholder('—')
                    ->description(fn (CompanyMembershipAuditLog $record): ?string => $record->memberContact?->phone_number),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (CompanyMembershipAuditLog $record): string => $record->actionLabel())
                    ->color(fn (CompanyMembershipAuditLog $record): string => match ($record->action) {
                        'access_enabled' => 'success',
                        'access_disabled' => 'danger',
                        'permissions_updated' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('actor')
                    ->label('By')
                    ->state(function (CompanyMembershipAuditLog $record): string {
                        if ($record->actorUser) {
                            return $record->actorUser->name
                                ?: ($record->actorUser->email ?? 'Staff #'.$record->actor_user_id);
                        }
                        if ($record->actorContact) {
                            return ($record->actorContact->name ?? 'Partner').' (partner)';
                        }

                        return 'System';
                    }),
                TextColumn::make('before')
                    ->label('Before')
                    ->formatStateUsing(fn ($state): string => is_array($state)
                        ? json_encode($state, JSON_UNESCAPED_SLASHES)
                        : '—')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('after')
                    ->label('After')
                    ->formatStateUsing(fn ($state): string => is_array($state)
                        ? json_encode($state, JSON_UNESCAPED_SLASHES)
                        : '—')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
