<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Models\Service;
use App\Services\BulkMessageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Compose a bulk SMS from company filters (services, TIN, active, phone).
 *
 * @property-read Schema $form
 */
class ComposeBulkMessage extends Page
{
    protected static string $resource = BulkMessageResource::class;

    protected static ?string $title = 'Compose from companies';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'message' => BulkMessageService::DEFAULT_MESSAGE,
            'service_ids' => [],
            'alive_subscriptions_only' => false,
            'tin_validated' => '',
            'is_active' => '',
            'require_phone' => true,
            'queue_after_create' => false,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Special bulk only — filter companies and send one announcement SMS. For monthly revenue collection use Monthly revenue.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Message')->schema([
                    TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(160),
                    Textarea::make('message')
                        ->label('SMS body')
                        ->required()
                        ->rows(6)
                        ->maxLength(640)
                        ->helperText('Placeholder: {company_name}. Max 640 characters.'),
                ]),
                Section::make('Audience filters')->schema([
                    Select::make('service_ids')
                        ->label('Services')
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->default([])
                        ->placeholder('Select one or more services')
                        ->options(fn (): array => Service::query()
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->get(['id', 'name', 'is_active'])
                            ->mapWithKeys(fn (Service $service): array => [
                                (string) $service->id => $service->is_active
                                    ? (string) $service->name
                                    : ((string) $service->name).' (inactive)',
                            ])
                            ->all())
                        ->helperText('Multi-select. Includes inactive services. Leave empty for all services. Matches companies with a subscription to any selected service.')
                        ->columnSpanFull(),
                    Toggle::make('alive_subscriptions_only')
                        ->label('Alive subscriptions only')
                        ->helperText('Only when services are selected: Active / Pending renewal / Grace (ignore expired / deactive).')
                        ->visible(fn (Get $get): bool => filled($get('service_ids'))),
                    Select::make('tin_validated')
                        ->label('TIN number verified (ERCA)')
                        ->options([
                            '' => 'Any',
                            '1' => 'Verified only',
                            '0' => 'Not verified only',
                        ])
                        ->native(false)
                        ->helperText('Not verified = companies that still need to activate / update their TIN.'),
                    Select::make('is_active')
                        ->label('Company active status')
                        ->options([
                            '' => 'Any',
                            '1' => 'Active only',
                            '0' => 'Inactive only',
                        ])
                        ->native(false),
                    Toggle::make('require_phone')
                        ->label('Must have phone')
                        ->default(true),
                    Toggle::make('queue_after_create')
                        ->label('Queue send immediately after create')
                        ->helperText('If off, you review the draft first then click Send pending.'),
                ])->columns(2),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('compose')
                    ->footer([
                        Actions::make([
                            Action::make('compose')
                                ->label('Create campaign')
                                ->submit('compose')
                                ->color('primary')
                                ->icon('heroicon-o-megaphone'),
                            Action::make('cancel')
                                ->label('Cancel')
                                ->color('gray')
                                ->url(BulkMessageResource::getUrl('index')),
                        ])->alignment(Alignment::Start),
                    ]),
            ]);
    }

    public function compose(BulkMessageService $bulkMessages): void
    {
        $data = $this->form->getState();

        $tin = $data['tin_validated'] ?? '';
        $tinValidated = $tin === '' || $tin === null ? null : ((string) $tin === '1');

        $activeRaw = $data['is_active'] ?? '';
        $isActive = $activeRaw === '' || $activeRaw === null ? null : ((string) $activeRaw === '1');

        $serviceIds = collect(Arr::wrap($data['service_ids'] ?? []))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        try {
            $campaign = $bulkMessages->createFromCompanies(
                auth()->user(),
                (string) ($data['title'] ?? ''),
                (string) ($data['message'] ?? ''),
                [
                    'is_active' => $isActive,
                    'tin_validated' => $tinValidated,
                    'service_ids' => $serviceIds,
                    'alive_subscriptions_only' => (bool) ($data['alive_subscriptions_only'] ?? false),
                    'require_phone' => (bool) ($data['require_phone'] ?? true),
                ],
            );

            if (! empty($data['queue_after_create'])) {
                $bulkMessages->queue($campaign->fresh());
                Notification::make()
                    ->title('Campaign created and queued')
                    ->body("{$campaign->matched_count} recipients queued for SMS.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Draft campaign created')
                    ->body("{$campaign->matched_count} recipients ready. Review then Send pending.")
                    ->success()
                    ->send();
            }

            $this->redirect(BulkMessageResource::getUrl('view', ['record' => $campaign]));
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not create campaign')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
