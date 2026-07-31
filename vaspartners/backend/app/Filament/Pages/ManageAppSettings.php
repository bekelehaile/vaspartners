<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

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

    public function mount(): void
    {
        $this->form->fill($this->formStateFromStore());
    }

    public function getSubheading(): ?string
    {
        return 'Partner portal login and external registry outages (ERCA TIN). Admin Filament login is unchanged.';
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

        $this->form->fill($this->formStateFromStore());

        Notification::make()
            ->title('App settings saved')
            ->body(
                'Sign-in: '.$this->authModeLabel($mode)
                .' · ERCA TIN: '.$this->ercaTinModeLabel($ercaMode)
            )
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formStateFromStore(): array
    {
        return [
            'auth_mode' => AppSetting::authMode(),
            'auth_mode_note' => AppSetting::getValue('auth_mode_note'),
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
