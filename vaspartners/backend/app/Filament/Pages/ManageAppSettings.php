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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Partner portal settings: sign-in, notifications, TIN lookup.
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
        return null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('App settings')
                    ->vertical()
                    ->persistTabInQueryString('tab')
                    ->tabs([
                        Tab::make('Sign-in')
                            ->icon('heroicon-o-lock-closed')
                            ->schema([
                                Select::make('auth_mode')
                                    ->label('Sign-in method')
                                    ->options([
                                        AppSetting::AUTH_MODE_FAYDA => 'Fayda only',
                                        AppSetting::AUTH_MODE_PHONE_OTP => 'Phone OTP only',
                                        AppSetting::AUTH_MODE_BOTH => 'Fayda and phone OTP',
                                    ])
                                    ->required()
                                    ->native(false),
                                Textarea::make('auth_mode_note')
                                    ->label('Login note')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->placeholder('Optional message on the partner login screen'),
                            ]),
                        Tab::make('Notifications')
                            ->icon('heroicon-o-bell')
                            ->schema([
                                Toggle::make('notify_partner_sms')
                                    ->label('SMS'),
                                Toggle::make('notify_partner_in_app')
                                    ->label('In-app'),
                                Toggle::make('notify_partner_email')
                                    ->label('Email')
                                    ->disabled()
                                    ->dehydrated(true),
                            ]),
                        Tab::make('TIN lookup')
                            ->icon('heroicon-o-building-library')
                            ->schema([
                                Select::make('erca_tin_mode')
                                    ->label('Status')
                                    ->options([
                                        AppSetting::ERCA_TIN_MODE_LIVE => 'Live',
                                        AppSetting::ERCA_TIN_MODE_MAINTENANCE => 'Maintenance',
                                    ])
                                    ->required()
                                    ->native(false),
                                Textarea::make('erca_tin_outage_message')
                                    ->label('Outage message')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->placeholder(AppSetting::DEFAULT_ERCA_TIN_OUTAGE_MESSAGE),
                                TextInput::make('test_erca_tin')
                                    ->label('Test TIN')
                                    ->placeholder('10 digits')
                                    ->maxLength(14)
                                    ->dehydrated(false),
                                Actions::make([
                                    Action::make('search_erca_tin')
                                        ->label('Test lookup')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->color('gray')
                                        ->action(function (EtradeTinLookupService $lookup): void {
                                            $this->runErcaProbe($lookup);
                                        }),
                                ])->alignment(Alignment::Start),
                                Placeholder::make('erca_probe_result')
                                    ->label('Result')
                                    ->content(fn (): HtmlString => new HtmlString($this->ercaProbeHtml()))
                                    ->visible(fn (): bool => $this->ercaProbe !== null),
                            ]),
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
                                ->label('Save')
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
        AppSetting::setBoolValue(
            AppSetting::KEY_NOTIFY_PARTNER_EMAIL,
            (bool) ($data['notify_partner_email'] ?? false),
        );

        $this->form->fill([
            ...$this->formStateFromStore(),
            'test_erca_tin' => $this->data['test_erca_tin'] ?? null,
        ]);

        Notification::make()
            ->title('Settings saved')
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
            'found' => 'TIN found',
            'not_found' => 'TIN not found',
            'invalid_tin' => 'Invalid TIN',
            'disabled' => 'Lookup not configured',
            'upstream_error', 'unavailable' => 'Lookup unavailable',
            default => 'Lookup finished',
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
            'TIN' => e((string) ($probe['tin'] ?? '—')),
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
                .'Maintenance is on for partners. This test still ran live.'
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
}
