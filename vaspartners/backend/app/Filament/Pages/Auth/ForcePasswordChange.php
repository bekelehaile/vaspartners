<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\Rules\Password;

/**
 * @property-read Schema $form
 */
class ForcePasswordChange extends SimplePage
{
    protected static bool $isDiscovered = false;

    protected static ?string $slug = 'force-password-change';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(Filament::auth()->check(), 403);

        /** @var User $user */
        $user = Filament::auth()->user();

        if (! $user->must_change_password) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Change password';
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
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('changePassword')
                    ->footer([
                        Actions::make([
                            Action::make('changePassword')
                                ->label('Update password')
                                ->submit('changePassword'),
                        ])
                            ->alignment(Alignment::Full)
                            ->fullWidth(true)
                            ->key('form-actions'),
                    ]),
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
}
