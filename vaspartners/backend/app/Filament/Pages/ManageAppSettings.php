<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Services\Etrade\EtradeTinLookupService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Controls partner portal sign-in and external endpoint outage behaviour.
 *
 * @property-read Schema $form
 */
class ManageAppSettings extends Page
{
    use HasPageShield;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?string $navigationLabel = 'App settings';

    protected static ?string $title = 'App settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'app-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Latest admin ERCA probe result (not persisted).
     *
     * @var array<string, mixed>|null
     */
    public ?array $ercaProbe = null;

    public function mount(): void
    {
        $this->form->fill($this->formStateFromStore());
    }

    public function getSubheading(): ?string
    {
        return 'Partner portal login, notification channels, and external registry outages (ERCA TIN). Admin Filament login is unchanged.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Partner portal sign-in')
                    ->description('Customers sign in on the public portal. Admin Filament login is unchanged.')
                    ->schema([
                        Select::make('auth_mode')
                            ->label('Authentication mode')
                            ->options([
                                AppSetting::AUTH_MODE_FAYDA => 'Fayda only',
                                AppSetting::AUTH_MODE_PHONE_OTP => 'Phone OTP only',
                                AppSetting::AUTH_MODE_BOTH => 'Both (Fayda + Phone OTP)',
                            ])
                            ->required()
                            ->native(false)
                            ->helperText('Phone OTP: SMS code → create/open contact → complete company profile. When Fayda prod is stable, set Fayda only.'),
                        Textarea::make('auth_mode_note')
                            ->label('Public note (optional)')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Shown on the partner login screen (e.g. temporary Fayda outage message).'),
                    ]),
                Section::make('Partner notifications')
                    ->description('Channels used for ticket, company, and membership events. Login OTP SMS is separate and always follows SMS_ENABLED.')
                    ->schema([
                        Toggle::make('notify_partner_sms')
                            ->label('SMS')
                            ->helperText('Queued partner SMS (requests, documents, company/TIN alerts, ad-hoc and bulk). Does not turn off portal login OTP.'),
                        Toggle::make('notify_partner_in_app')
                            ->label('In-app (portal)')
                            ->helperText('Database notifications shown in the partner portal inbox.'),
                        Toggle::make('notify_partner_email')
                            ->label('Email')
                            ->helperText('Not wired yet — toggle is saved for later; no email is sent today.')
                            ->disabled()
                            ->dehydrated(true),
                    ]),
                Section::make('External endpoints')
                    ->description('When a national registry is down, put it in maintenance. TIN writes stay fail-closed (no bypass). Partners see your outage message.')
                    ->schema([
                        Select::make('erca_tin_mode')
                            ->label('ERCA TIN number lookup')
                            ->options([
                                AppSetting::ERCA_TIN_MODE_LIVE => 'Live (call ERCA / eTrade)',
                                AppSetting::ERCA_TIN_MODE_MAINTENANCE => 'Maintenance (outage — block TIN create/update)',
                            ])
                            ->required()
                            ->native(false)
                            ->helperText('Maintenance stops new TIN verification and company create/update that need ERCA. Cached successful lookups are not used while maintenance is on.'),
                        Textarea::make('erca_tin_outage_message')
                            ->label('ERCA outage message')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder(AppSetting::DEFAULT_ERCA_TIN_OUTAGE_MESSAGE)
                            ->helperText('Shown to partners when ERCA is in maintenance or the upstream call fails. Leave blank for the default message.'),
                        TextInput::make('test_erca_tin')
                            ->label('Test TIN number')
                            ->placeholder('10 digits')
                            ->maxLength(14)
                            ->dehydrated(false)
                            ->helperText('Admin probe only — calls ERCA live (ignores Maintenance mode; does not bypass TIN write rules).'),
                        Actions::make([
                            Action::make('search_erca_tin')
                                ->label('Search ERCA')
                                ->icon('heroicon-o-magnifying-glass')
                                ->color('gray')
                                ->action(function (EtradeTinLookupService $lookup): void {
                                    $this->runErcaProbe($lookup);
                                }),
                        ])->alignment(Alignment::Start),
                        Placeholder::make('erca_probe_result')
                            ->label('Test result')
                            ->content(fn (): HtmlString => new HtmlString($this->ercaProbeHtml()))
                            ->visible(fn (): bool => $this->ercaProbe !== null),
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
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save settings')
                                ->submit('save')
                                ->color('primary')
                                ->icon('heroicon-o-check'),
                        ])->alignment(Alignment::Start),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $mode = (string) ($data['auth_mode'] ?? AppSetting::AUTH_MODE_BOTH);
        if (! in_array($mode, [
            AppSetting::AUTH_MODE_FAYDA,
            AppSetting::AUTH_MODE_PHONE_OTP,
            AppSetting::AUTH_MODE_BOTH,
        ], true)) {
            $mode = AppSetting::AUTH_MODE_BOTH;
        }

        $ercaMode = (string) ($data['erca_tin_mode'] ?? AppSetting::ERCA_TIN_MODE_LIVE);
        if (! in_array($ercaMode, [
            AppSetting::ERCA_TIN_MODE_LIVE,
            AppSetting::ERCA_TIN_MODE_MAINTENANCE,
        ], true)) {
            $ercaMode = AppSetting::ERCA_TIN_MODE_LIVE;
        }

        AppSetting::setValue(AppSetting::KEY_AUTH_MODE, $mode);
        AppSetting::setValue(
            'auth_mode_note',
            filled($data['auth_mode_note'] ?? null)
                ? trim((string) $data['auth_mode_note'])
                : null
        );
        AppSetting::setValue(AppSetting::KEY_ERCA_TIN_MODE, $ercaMode);
        AppSetting::setValue(
            AppSetting::KEY_ERCA_TIN_OUTAGE_MESSAGE,
            filled($data['erca_tin_outage_message'] ?? null)
                ? trim((string) $data['erca_tin_outage_message'])
                : null
        );

        AppSetting::setBoolValue(
            AppSetting::KEY_NOTIFY_PARTNER_SMS,
            (bool) ($data['notify_partner_sms'] ?? true),
        );
        AppSetting::setBoolValue(
            AppSetting::KEY_NOTIFY_PARTNER_IN_APP,
            (bool) ($data['notify_partner_in_app'] ?? true),
        );
        // Email channel is not delivered yet; keep stored preference when enabled later.
        AppSetting::setBoolValue(
            AppSetting::KEY_NOTIFY_PARTNER_EMAIL,
            (bool) ($data['notify_partner_email'] ?? false),
        );

        $this->form->fill([
            ...$this->formStateFromStore(),
            'test_erca_tin' => $this->data['test_erca_tin'] ?? null,
        ]);

        $channels = AppSetting::partnerNotificationChannels();
        $channelSummary = collect([
            $channels['sms'] ? 'SMS on' : 'SMS off',
            $channels['in_app'] ? 'In-app on' : 'In-app off',
            $channels['email'] ? 'Email on' : 'Email off',
        ])->implode(', ');

        Notification::make()
            ->title('App settings saved')
            ->body(
                'Sign-in: '.$this->authModeLabel($mode)
                .' · ERCA TIN: '.$this->ercaTinModeLabel($ercaMode)
                .' · Notifications: '.$channelSummary
            )
            ->success()
            ->send();
    }

    public function runErcaProbe(EtradeTinLookupService $lookup): void
    {
        $raw = (string) ($this->data['test_erca_tin'] ?? '');
        if (! filled(trim($raw))) {
            Notification::make()
                ->title('Enter a TIN number')
                ->warning()
                ->send();

            return;
        }

        $this->ercaProbe = $lookup->adminProbe($raw, useCache: false);

        $probe = $this->ercaProbe;
        $title = match ($probe['status'] ?? '') {
            'found' => 'ERCA: TIN found',
            'not_found' => 'ERCA: TIN not found',
            'invalid_tin' => 'Invalid TIN number',
            'disabled' => 'eTrade not configured',
            'upstream_error', 'unavailable' => 'ERCA unreachable',
            default => 'ERCA probe finished',
        };

        $notification = Notification::make()
            ->title($title)
            ->body((string) ($probe['message'] ?? ''));

        if (($probe['ok'] ?? false) && ($probe['found'] ?? false)) {
            $notification->success();
        } elseif (($probe['status'] ?? '') === 'not_found') {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    protected function ercaProbeHtml(): string
    {
        $probe = $this->ercaProbe;
        if (! is_array($probe)) {
            return '<span class="text-sm text-gray-500">—</span>';
        }

        $rows = [
            'Status' => e((string) ($probe['status'] ?? '—')),
            'TIN number' => e((string) ($probe['tin'] ?? '—')),
            'Found' => ($probe['found'] ?? false) ? 'Yes' : 'No',
            'Legal name' => e((string) ($probe['legal_name'] ?: '—')),
            'Business name' => e((string) ($probe['business_name'] ?: '—')),
            'Entity type' => e((string) ($probe['entity_type'] ?: '—')),
            'Tax centre' => e((string) ($probe['tax_centre'] ?: '—')),
            'Region' => e((string) ($probe['region'] ?: '—')),
            'City' => e((string) ($probe['city'] ?: '—')),
            'Message' => e((string) ($probe['message'] ?: '—')),
        ];

        $html = '<dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">';
        foreach ($rows as $label => $value) {
            $html .= '<div><dt class="font-medium text-gray-500 dark:text-gray-400">'.$label
                .'</dt><dd class="text-gray-950 dark:text-white">'.$value.'</dd></div>';
        }
        $html .= '</dl>';

        if (AppSetting::ercaTinInMaintenance()) {
            $html .= '<p class="mt-3 text-sm text-warning-600 dark:text-warning-400">'
                .'App setting is Maintenance — partners are blocked. This admin search still called ERCA live.'
                .'</p>';
        }

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formStateFromStore(): array
    {
        return [
            'auth_mode' => AppSetting::authMode(),
            'auth_mode_note' => AppSetting::getValue('auth_mode_note'),
            'notify_partner_sms' => AppSetting::partnerSmsEnabled(),
            'notify_partner_in_app' => AppSetting::partnerInAppEnabled(),
            'notify_partner_email' => AppSetting::partnerEmailEnabled(),
            'erca_tin_mode' => AppSetting::ercaTinMode(),
            'erca_tin_outage_message' => AppSetting::getValue(AppSetting::KEY_ERCA_TIN_OUTAGE_MESSAGE),
        ];
    }

    protected function authModeLabel(string $mode): string
    {
        return match ($mode) {
            AppSetting::AUTH_MODE_FAYDA => 'Fayda only',
            AppSetting::AUTH_MODE_PHONE_OTP => 'Phone OTP only',
            default => 'Both (Fayda + Phone OTP)',
        };
    }

    protected function ercaTinModeLabel(string $mode): string
    {
        return $mode === AppSetting::ERCA_TIN_MODE_MAINTENANCE
            ? 'Maintenance'
            : 'Live';
    }
}
