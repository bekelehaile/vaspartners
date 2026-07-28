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
 * Controls partner (customer) portal sign-in: Fayda and/or phone OTP.
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
        $this->form->fill([
            'auth_mode' => AppSetting::authMode(),
            'auth_mode_note' => AppSetting::getValue('auth_mode_note'),
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Partner portal login only (not admin). Use phone OTP while Fayda production is unstable, then switch to Fayda only when ready.';
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

        AppSetting::setValue(AppSetting::KEY_AUTH_MODE, $mode);
        AppSetting::setValue(
            'auth_mode_note',
            filled($data['auth_mode_note'] ?? null)
                ? trim((string) $data['auth_mode_note'])
                : null
        );

        $this->form->fill([
            'auth_mode' => $mode,
            'auth_mode_note' => AppSetting::getValue('auth_mode_note'),
        ]);

        Notification::make()
            ->title('App settings saved')
            ->body('Partner portal sign-in: '.$this->authModeLabel($mode))
            ->success()
            ->send();
    }

    protected function authModeLabel(string $mode): string
    {
        return match ($mode) {
            AppSetting::AUTH_MODE_FAYDA => 'Fayda only',
            AppSetting::AUTH_MODE_PHONE_OTP => 'Phone OTP only',
            default => 'Both (Fayda + Phone OTP)',
        };
    }
}
