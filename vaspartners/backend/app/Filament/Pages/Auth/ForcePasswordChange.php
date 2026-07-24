<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Password;

/**
 * @property-read Schema $form
 */
class ForcePasswordChange extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Key;

    protected static ?string $navigationLabel = 'Change password';

    protected static ?string $title = 'Change password';

    protected static ?string $slug = 'force-password-change';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        abort_unless($user !== null, 403);

        if (! $user->must_change_password) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function getHeading(): string
    {
        return 'Set a new password';
    }

    public function getSubheading(): ?string
    {
        return 'For security, you must replace your temporary password before continuing.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::defaults())
                    ->autocomplete('new-password')
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('new-password')
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Form::make([EmbeddedSchema::make('form')])
                            ->id('form')
                            ->livewireSubmitHandler('changePassword')
                            ->footer([
                                Actions::make([
                                    Action::make('changePassword')
                                        ->label('Update password')
                                        ->submit('changePassword'),
                                ])
                                    ->alignment(Alignment::Start)
                                    ->key('form-actions'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public function changePassword(): void
    {
        /** @var array{password: string} $data */
        $data = $this->form->getState();

        /** @var User $user */
        $user = Filament::auth()->user();

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        if (request()->hasSession()) {
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
            ]);
        }

        Notification::make()
            ->title('Password updated')
            ->body('You can now use the admin panel.')
            ->success()
            ->send();

        $this->redirect(Filament::getUrl());
    }

    public static function canAccess(): bool
    {
        if (\STS\FilamentImpersonate\Facades\Impersonation::isImpersonating()) {
            return false;
        }

        $user = Filament::auth()->user();

        return $user instanceof User && (bool) $user->must_change_password;
    }
}
