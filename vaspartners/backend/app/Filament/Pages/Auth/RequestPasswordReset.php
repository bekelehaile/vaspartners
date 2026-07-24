<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SmsService;
use App\Support\AdminLoginResolver;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Phone number')
            ->tel()
            ->required()
            ->autocomplete('tel')
            ->autofocus();
    }

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $user = AdminLoginResolver::resolve((string) ($data['login'] ?? ''));

        if (
            $user instanceof User
            && (! ($user instanceof FilamentUser) || $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
            && filled($user->phone)
        ) {
            $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $user->email],
                function (User $resetUser, #[SensitiveParameter] string $token): void {
                    $url = Filament::getResetPasswordUrl($token, $resetUser);
                    $message = "VAS Partners admin password reset.\n"
                        ."Open this link to set a new password:\n{$url}\n"
                        .'If you did not request this, ignore this message. Ethio telecom';

                    app(SmsService::class)->send($resetUser->phone, $message);

                    if (class_exists(PasswordResetLinkSent::class)) {
                        event(new PasswordResetLinkSent($resetUser));
                    }
                },
            );

            // Ignore broker status for UX; always acknowledge without enumeration.
            unset($status);
        }

        Notification::make()
            ->title('Password reset link sent')
            ->body('If an account matches, a reset link was sent by SMS to the registered phone number.')
            ->success()
            ->send();

        $this->form->fill();
    }
}
