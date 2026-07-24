<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketDocument;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Attachments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->documents()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Customer uploads from all tickets on this subscription. Open a ticket for document review.')
            ->modifyQueryUsing(fn ($query) => $query->with(['documentType', 'ticket']))
            ->columns([
                TextColumn::make('ticket.tt_number')
                    ->label('Request number')
                    ->url(fn (TicketDocument $record): ?string => $record->ticket
                        ? TicketResource::getUrl('view', ['record' => $record->ticket])
                        : null)
                    ->color('primary'),
                TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                IconColumn::make('file_ok')
                    ->label('On file')
                    ->boolean()
                    ->getStateUsing(fn (TicketDocument $record): bool => $this->fileExists($record)),
                TextColumn::make('original_name')
                    ->label('File')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('mime_type')
                    ->label('Type')
                    ->toggleable(),
                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(function (?int $state): string {
                        if (! $state) {
                            return '—';
                        }
                        if ($state < 1024) {
                            return $state.' B';
                        }

                        return number_format($state / 1024, 1).' KB';
                    }),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(
                        fn (TicketDocument $record): string => route('filament.admin.ticket-documents.open', $record),
                        shouldOpenInNewTab: true,
                    )
                    ->visible(fn (TicketDocument $record): bool => $this->fileExists($record)),
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(
                        fn (TicketDocument $record): string => route('filament.admin.ticket-documents.download', $record),
                        shouldOpenInNewTab: true,
                    )
                    ->visible(fn (TicketDocument $record): bool => $this->fileExists($record)),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No attachments yet')
            ->emptyStateDescription('Files uploaded on linked service requests will appear here.')
            ->paginated([25, 50, 100]);
    }

    protected function fileExists(TicketDocument $document): bool
    {
        if (blank($document->path)) {
            return false;
        }

        return Storage::disk($document->disk ?: 'local')->exists($document->path);
    }
}
