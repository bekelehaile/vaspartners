<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\PartnerNotificationService;
use App\Services\TicketCommentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Messages';

    protected static ?string $recordTitleAttribute = 'body';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        $maxKb = app(TicketCommentService::class)->maxAttachmentKb();

        return $schema->components([
            Textarea::make('body')
                ->label('Message')
                ->rows(4)
                ->maxLength(5000)
                ->helperText('Ask for missing documents or clarify requirements. Optional if you attach a PDF.'),
            FileUpload::make('attachment')
                ->label('PDF attachment')
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize($maxKb)
                ->storeFiles(false)
                ->helperText("Optional. PDF only, max {$maxKb} KB."),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Ticket $ticket */
        $ticket = $this->getOwnerRecord();
        $locked = $ticket->status->locksCustomerDocuments();

        return $table
            ->description($locked
                ? 'Messaging is closed for completed/closed requests.'
                : 'Chat with the partner about missing documents or extra information. Small PDF attachments allowed.')
            ->modifyQueryUsing(fn ($query) => $query->where('is_public', true)->with('author')->latest('id'))
            ->columns([
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
                    ->limit(120),
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
            ->headerActions([
                CreateAction::make()
                    ->label('Send message')
                    ->modalHeading('Message the partner')
                    ->createAnother(false)
                    ->visible(fn (): bool => ! $locked && auth()->user()?->can('update', $ticket))
                    ->using(function (array $data) use ($ticket): Model {
                        /** @var User $user */
                        $user = auth()->user();
                        $service = app(TicketCommentService::class);
                        $file = $this->resolveUploadedFile($data['attachment'] ?? null);

                        $comment = $service->post($ticket, $user, $data['body'] ?? null, $file);
                        app(PartnerNotificationService::class)->ticketMessagePosted($ticket, $user, $comment);

                        return $comment;
                    }),
            ])
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
            ->toolbarActions([])
            ->bulkActions([])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Start a conversation when documents are missing or you need more detail from the partner.')
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100]);
    }

    protected function resolveUploadedFile(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($value)) {
            return null;
        }

        $absolute = $disk->path($value);

        return new UploadedFile(
            $absolute,
            basename($value),
            mime_content_type($absolute) ?: 'application/pdf',
            null,
            true,
        );
    }
}
