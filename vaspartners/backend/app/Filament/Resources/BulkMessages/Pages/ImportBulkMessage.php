<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Services\BulkMessageService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Dedicated import screen for special-list bulk SMS (not monthly revenue).
 *
 * @property-read Schema $form
 */
class ImportBulkMessage extends Page
{
    protected static string $resource = BulkMessageResource::class;

    protected static ?string $title = 'Import special list';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'message' => BulkMessageService::DEFAULT_MESSAGE,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Special bulk only — upload a phone list (CSV/Excel). Rows match company revenue / Claim phone; SMS goes to revenue phone. For revenue collection use Monthly revenue.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Message')
                    ->description('Write any special announcement. Use {company_name} for the matched company name.')
                    ->schema([
                        TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('e.g. Portal maintenance notice'),
                        Textarea::make('message')
                            ->label('SMS body')
                            ->required()
                            ->rows(6)
                            ->maxLength(640)
                            ->default(BulkMessageService::DEFAULT_MESSAGE)
                            ->helperText('Placeholder: {company_name}. Max 640 characters.'),
                    ]),
                Section::make('Phone list')
                    ->description('Column: phone')
                    ->schema([
                        FileUpload::make('spreadsheet')
                            ->label('Excel / CSV file')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'text/plain',
                                'application/csv',
                            ])
                            ->disk('local')
                            ->directory('bulk-messages/imports')
                            ->visibility('private')
                            ->required()
                            ->helperText('Header: phone'),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('import')
                    ->footer([
                        Actions::make([
                            Action::make('import')
                                ->label('Import recipients')
                                ->submit('import')
                                ->color('primary')
                                ->icon('heroicon-o-arrow-up-tray'),
                            Action::make('cancel')
                                ->label('Cancel')
                                ->color('gray')
                                ->url(BulkMessageResource::getUrl('index')),
                        ])->alignment(Alignment::Start),
                    ]),
            ]);
    }

    public function import(BulkMessageService $bulkMessages): void
    {
        $data = $this->form->getState();

        $path = $data['spreadsheet'] ?? null;
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            Notification::make()
                ->title('Upload required')
                ->body('Please upload an Excel or CSV file.')
                ->danger()
                ->send();

            return;
        }

        try {
            $record = $bulkMessages->createFromStoredPath(
                auth()->user(),
                (string) ($data['title'] ?? ''),
                (string) ($data['message'] ?? ''),
                $path,
                basename($path),
            );

            Notification::make()
                ->title('Import queued')
                ->body('Recipients are being matched in the background. Refresh this page when status is Draft, then send.')
                ->success()
                ->send();

            $this->redirect(BulkMessageResource::getUrl('view', ['record' => $record]));
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Import failed')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
