<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketComment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Messages';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->comments()->where('is_public', true)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Public messages across all tickets on this subscription. Reply from the related ticket view.')
            ->modifyQueryUsing(fn ($query) => $query
                ->where('ticket_comments.is_public', true)
                ->with(['author', 'ticket']))
            ->columns([
                TextColumn::make('ticket.tt_number')
                    ->label('Request number')
                    ->url(fn (TicketComment $record): ?string => $record->ticket
                        ? TicketResource::getUrl('view', ['record' => $record->ticket])
                        : null)
                    ->color('primary'),
                TextColumn::make('author_label')
                    ->label('From')
                    ->state(function (TicketComment $record): string {
                        $author = $record->author;
                        if ($author instanceof User) {
                            return $author->name ?: 'Account manager';
                        }

                        return $author->name ?? 'Partner';
                    }),
                TextColumn::make('author_role')
                    ->label('Role')
                    ->badge()
                    ->state(fn (TicketComment $record): string => $record->author instanceof User ? 'Staff' : 'Partner')
                    ->color(fn (TicketComment $record): string => $record->author instanceof User ? 'primary' : 'success'),
                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('—'),
                IconColumn::make('has_pdf')
                    ->label('PDF')
                    ->boolean()
                    ->getStateUsing(fn (TicketComment $record): bool => $record->hasAttachment()),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label('Download PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(
                        fn (TicketComment $record): string => route('filament.admin.ticket-comments.attachment', $record),
                        shouldOpenInNewTab: true,
                    )
                    ->visible(fn (TicketComment $record): bool => $record->hasAttachment()),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Partner and staff chat on linked tickets will show here.')
            ->paginated([25, 50, 100]);
    }
}
