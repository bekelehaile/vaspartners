<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyMembershipAuditLog;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ->paginated([10, 25, 50])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (CompanyMembershipAuditLog $record): string => sprintf(
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
                        TextInput::make('member')
                            ->label('Member')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('by')
                            ->label('By')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(4)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Textarea::make('before')
                            ->label('Before')
                            ->rows(8)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Textarea::make('after')
                            ->label('After')
                            ->rows(8)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->fillForm(function (CompanyMembershipAuditLog $record): array {
                        if ($record->actorUser) {
                            $by = $record->actorUser->name
                                ?: ($record->actorUser->email ?? 'Staff #'.$record->actor_user_id);
                        } elseif ($record->actorContact) {
                            $by = ($record->actorContact->name ?? 'Partner').' (partner)';
                        } else {
                            $by = 'System';
                        }

                        $member = $record->memberContact?->name ?: '—';
                        if ($record->memberContact?->phone_number) {
                            $member .= ' · '.$record->memberContact->phone_number;
                        }

                        return [
                            'action' => $record->actionLabel(),
                            'member' => $member,
                            'by' => $by,
                            'note' => filled($record->note) ? (string) $record->note : '—',
                            'before' => is_array($record->before) && $record->before !== []
                                ? json_encode($record->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—',
                            'after' => is_array($record->after) && $record->after !== []
                                ? json_encode($record->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—',
                        ];
                    }),
            ])
            ->toolbarActions([]);
    }
}
