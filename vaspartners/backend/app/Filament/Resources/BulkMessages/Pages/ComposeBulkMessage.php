<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\ValidationException;

/**
 * Compose a bulk SMS from company filters (Active, approval, TIN, etc.).
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
            'title' => 'Portal upgrade notice',
            'message' => 'Dear Our Partner, we have updated the VAS partner management portal to a new version. Please provide your TIN NUMBER to complete your profile. Wait until it is verified/approved before next system access. https://vaspartnersportal.ethiotelecom.et',
            'is_active' => true,
            'approval_status' => CompanyApprovalStatus::Approved->value,
            'legacy_only' => true,
            'require_phone' => true,
            'queue_after_create' => false,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Build recipients from company filters. Review the draft, then Send / Re-send failed from the campaign page.';
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
                        ->helperText('Include the portal URL. Max 640 characters. Placeholders like {company_name} are supported.'),
                ]),
                Section::make('Audience filters')->schema([
                    Toggle::make('is_active')
                        ->label('Active companies only')
                        ->default(true),
                    Select::make('approval_status')
                        ->label('Approval status')
                        ->options([
                            '' => 'Any',
                            CompanyApprovalStatus::Approved->value => 'Approved',
                            CompanyApprovalStatus::Pending->value => 'Pending',
                            CompanyApprovalStatus::Rejected->value => 'Rejected',
                        ])
                        ->native(false),
                    Select::make('tin_validated')
                        ->label('TIN NUMBER approved')
                        ->options([
                            '' => 'Any',
                            '1' => 'Approved only',
                            '0' => 'Not approved only',
                        ])
                        ->native(false),
                    Toggle::make('legacy_only')
                        ->label('Migrated MVAS partners only')
                        ->default(true)
                        ->helperText('Companies with a legacy MVAS id.'),
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

        $approval = trim((string) ($data['approval_status'] ?? ''));

        try {
            $campaign = $bulkMessages->createFromCompanies(
                auth()->user(),
                (string) ($data['title'] ?? ''),
                (string) ($data['message'] ?? ''),
                [
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'approval_status' => $approval !== '' ? $approval : null,
                    'tin_validated' => $tinValidated,
                    'legacy_only' => (bool) ($data['legacy_only'] ?? false),
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
