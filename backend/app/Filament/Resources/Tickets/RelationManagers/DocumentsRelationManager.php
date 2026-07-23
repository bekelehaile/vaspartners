<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\TicketDocument;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Customer attachments';

    protected static ?string $recordTitleAttribute = 'original_name';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->searchable()
                    ->sortable(),
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
            ->headerActions([])
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
            ->toolbarActions([])
            ->bulkActions([])
            ->emptyStateHeading('No attachments yet')
            ->emptyStateDescription('Files the customer uploads for this request will appear here. Staff can open or download them, but cannot delete them.')
            ->paginated(false);
    }

    protected function fileExists(TicketDocument $document): bool
    {
        if (blank($document->path)) {
            return false;
        }

        return Storage::disk($document->disk ?: 'local')->exists($document->path);
    }
}
