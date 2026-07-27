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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dedicated import screen for bulk company SMS.
 *
 * @property-read Schema $form
 */
class ImportBulkMessage extends Page
{
    protected static string $resource = BulkMessageResource::class;

    protected static ?string $title = 'Import bulk message';

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
        return 'Upload phones + revenue fields (CSV/Excel). Company is matched by phone; SMS uses the template placeholders below.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (BulkMessageService $bulkMessages): StreamedResponse {
                    $csv = $bulkMessages->templateCsv();

                    return response()->streamDownload(
                        function () use ($csv): void {
                            echo $csv;
                        },
                        'bulk-message-template.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Message')
                    ->description('Template format: Dear {company_name}, your {period}, {service_type} revenue with Service ID {service_id} is ETB {amount}. Please provide the request letter with amount and ref number. Thank You Ethio Telecom')
                    ->schema([
                        TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('e.g. June 2026 revenue collection'),
                        Textarea::make('message')
                            ->label('SMS body')
                            ->required()
                            ->rows(6)
                            ->maxLength(640)
                            ->default(BulkMessageService::DEFAULT_MESSAGE)
                            ->helperText('Placeholders: {company_name} {period} {service_type} {service_id} {amount}. Sample: Dear Teleport Technology PLC, your June 2026, API revenue with Service ID 1000000002 is ETB 10,000. Please provide the request letter with amount and ref number. Thank You Ethio Telecom'),
                    ]),
                Section::make('Import recipients')
                    ->description('Columns: phone (required), period, service_type, service_id, amount. Company name/TIN come from the matched company.')
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
                            ->helperText('Download the template for the expected headers.'),
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
                            Action::make('template')
                                ->label('Download template')
                                ->color('gray')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->action(function (BulkMessageService $bulkMessages): StreamedResponse {
                                    $csv = $bulkMessages->templateCsv();

                                    return response()->streamDownload(
                                        function () use ($csv): void {
                                            echo $csv;
                                        },
                                        'bulk-message-template.csv',
                                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                                    );
                                }),
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
