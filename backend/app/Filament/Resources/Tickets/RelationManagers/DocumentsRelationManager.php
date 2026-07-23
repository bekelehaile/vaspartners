<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
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

    public static function getBadge(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Ticket $ownerRecord */
        $status = app(TicketWorkflowService::class)->attachmentStatus($ownerRecord);

        return match ($status['state']) {
            'complete' => 'Complete',
            'incomplete' => (string) $status['missing_count'].' missing',
            default => null,
        };
    }

    public static function getBadgeColor(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Ticket $ownerRecord */
        $status = app(TicketWorkflowService::class)->attachmentStatus($ownerRecord);

        return match ($status['state']) {
            'complete' => 'success',
            'incomplete' => 'danger',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        /** @var Ticket $ticket */
        $ticket = $this->getOwnerRecord();
        $status = app(TicketWorkflowService::class)->attachmentStatus($ticket);
        $description = match ($status['state']) {
            'complete' => '✓ All required documents received ('.$status['uploaded_count'].'/'.$status['required_count'].'). Open or download below — deletion is not allowed.',
            'incomplete' => '⚠ Missing required ('.$status['uploaded_count'].'/'.$status['required_count'].'): '.implode(', ', $status['missing_names']),
            default => 'No hard-required documents for this request type. Optional uploads may still appear below.',
        };

        return $table
            ->description($description)
            ->columns([
                TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->searchable()
                    ->sortable(),
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
            ->emptyStateHeading($status['state'] === 'incomplete' ? 'Required attachments missing' : 'No attachments uploaded yet')
            ->emptyStateDescription(
                $status['state'] === 'incomplete'
                    ? 'Missing: '.implode(', ', $status['missing_names'])
                    : 'When the customer uploads files, they will appear here for open/download only.'
            )
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
