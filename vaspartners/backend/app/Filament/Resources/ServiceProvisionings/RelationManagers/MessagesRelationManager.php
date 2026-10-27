<?php

namespace App\Filament\Resources\ServiceProvisionings\RelationManagers;

use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningMessage;
use App\Models\User;
use App\Services\PartnerNotificationService;
use App\Services\Provisioning\ServiceProvisioningMessageService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    protected static ?string $recordTitleAttribute = 'body';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->messages()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        $maxKb = app(ServiceProvisioningMessageService::class)->maxAttachmentKb();

        return $schema
            ->columns(2)
            ->components([
                Toggle::make('notify_partner')
                    ->label('Notify partner')
                    ->helperText('Off = internal only. On = visible to partner + portal/SMS alert.')
                    ->default(true),
                Textarea::make('body')
                    ->label('Message')
                    ->rows(5)
                    ->maxLength(5000)
                    ->columnSpanFull()
                    ->helperText('Guide the partner through credential setup and configuration. Optional if you attach a PDF.'),
                FileUpload::make('attachment')
                    ->label('Attachment')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize($maxKb)
                    ->storeFiles(false)
                    ->columnSpanFull()
                    ->helperText("Optional. PDF only, max {$maxKb} KB."),
            ]);
    }

    public function table(Table $table): Table
    {
        /** @var ServiceProvisioning $case */
        $case = $this->getOwnerRecord();
        $locked = app(ServiceProvisioningMessageService::class)->chatLocked($case);

        return $table
            ->description($locked
                ? 'Go Live is done — partner should use Subscription → Manage. Chat is closed for new messages.'
                : 'Chat with the partner while they configure credentials. After Go Live they use Subscription Manage.')
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->columns([
                TextColumn::make('visibility')
                    ->label('Visibility')
                    ->badge()
                    ->state(fn (ServiceProvisioningMessage $record): string => $record->is_public ? 'Partner' : 'Internal')
                    ->color(fn (ServiceProvisioningMessage $record): string => $record->is_public ? 'success' : 'gray'),
                TextColumn::make('author_label')
                    ->label('From')
                    ->state(function (ServiceProvisioningMessage $record): string {
                        $author = $record->author;
                        if ($author instanceof User) {
                            return $author->name ?: 'Account manager';
                        }
                        if ($author instanceof Contact) {
                            return $author->name ?: 'Partner';
                        }

                        return 'Unknown';
                    }),
                TextColumn::make('author_role')
                    ->label('Role')
                    ->badge()
                    ->state(fn (ServiceProvisioningMessage $record): string => $record->author instanceof User ? 'Staff' : 'Partner')
                    ->color(fn (ServiceProvisioningMessage $record): string => $record->author instanceof User ? 'primary' : 'success'),
                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->limit(80),
                IconColumn::make('has_pdf')
                    ->label('Attachment')
                    ->boolean()
                    ->getStateUsing(fn (ServiceProvisioningMessage $record): bool => $record->hasAttachment()),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_public')
                    ->label('Partner-visible')
                    ->trueLabel('Partner only')
                    ->falseLabel('Internal only')
                    ->placeholder('All'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Add message')
                    ->slideOver()
                    ->modalHeading('Message partner / internal note')
                    ->createAnother(false)
                    ->visible(fn (): bool => ! $locked)
                    ->using(function (array $data) use ($case): Model {
                        /** @var User $user */
                        $user = auth()->user();
                        $service = app(ServiceProvisioningMessageService::class);
                        $file = $this->resolveUploadedFile($data['attachment'] ?? null);
                        $notifyPartner = (bool) ($data['notify_partner'] ?? false);

                        $message = $service->post(
                            $case,
                            $user,
                            $data['body'] ?? null,
                            $file,
                            isPublic: $notifyPartner,
                        );

                        if ($notifyPartner) {
                            app(PartnerNotificationService::class)->provisioningMessagePosted($case, $user, $message);
                        }

                        return $message;
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(false)
                    ->color('gray')
                    ->button()
                    ->slideOver()
                    ->modalHeading(fn (ServiceProvisioningMessage $record): string => sprintf(
                        'Message · %s',
                        $record->created_at?->format('M j, Y H:i') ?? '—',
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        Textarea::make('body')
                            ->label('Message')
                            ->rows(16)
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->fillForm(fn (ServiceProvisioningMessage $record): array => [
                        'body' => (string) $record->body,
                    ])
                    ->visible(fn (ServiceProvisioningMessage $record): bool => filled($record->body)),
            ])
            ->toolbarActions([])
            ->bulkActions([])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Message the partner to help them configure credentials. After Go Live they use Subscription Manage.')
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
