<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\ThrottlesAuthByIdentifier;
use App\Models\User;
use App\Services\AdminPasswordOtpService;
use App\Services\SmsService;
use App\Support\AdminLoginResolver;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    use ThrottlesAuthByIdentifier;

    private const RATE_LIMIT_PREFIX = 'filament-password-reset-request';

    private const RATE_LIMIT_MAX_ATTEMPTS = 5;

    private const RATE_LIMIT_DECAY_SECONDS = 60;

    public function getTitle(): string|Htmlable
    {
        return 'Forgot password?';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Forgot password?';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getPhoneFormComponent(),
            ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Phone number')
            ->tel()
            ->helperText('Enter your phone number to receive an OTP.')
            ->required()
            ->autocomplete('tel')
            ->autofocus();
    }

    public function request(): void
    {
        $data = $this->form->getState();
        $phone = trim((string) ($data['phone'] ?? ''));

        $normalized = app(SmsService::class)->normalizePhone($phone);
        if ($normalized === '' || ! preg_match('/^\d{9}$/', $normalized)) {
            throw ValidationException::withMessages([
                'data.phone' => 'Please enter a valid phone number.',
            ]);
        }

        $this->ensureNotAuthThrottled(
            self::RATE_LIMIT_PREFIX,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            'data.phone',
        );

        $user = AdminLoginResolver::resolve($phone);

        if (
            ! $user instanceof User
            || ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
            || ! filled($user->phone)
        ) {
            $this->hitAuthThrottle(self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'data.phone' => 'No account found with this phone number.',
            ]);
        }

        try {
            app(AdminPasswordOtpService::class)->send($user->phone);

            $this->clearAuthThrottle(self::RATE_LIMIT_PREFIX);

            Notification::make()
                ->title('OTP sent')
                ->body('Please check your phone for the verification code.')
                ->success()
                ->send();

            $this->form->fill();

            // Signed reset URL (token is a placeholder; verification uses OTP), same as fixedservices.
            $this->redirect(Filament::getResetPasswordUrl(
                token: 'otp',
                user: $user,
            ));
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Too many attempts. Please try again later.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            Log::error('Password reset OTP request failed', [
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Failed to send OTP. Please try again.')
                ->danger()
                ->send();
        }
    }

    protected function getRequestFormAction(): Action
    {
        return Action::make('request')
            ->label('Send OTP')
            ->submit('request');
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.actions.login.label'))
            ->icon(match (__('filament-panels::layout.direction')) {
                'rtl' => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL) ?? Heroicon::ArrowRight,
                default => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN) ?? Heroicon::ArrowLeft,
            })
            ->url(filament()->getLoginUrl());
    }

    protected function authRateLimitIdentifier(): string
    {
        $phone = trim((string) ($this->data['phone'] ?? ''));

        if ($phone === '') {
            return '';
        }

        return app(SmsService::class)->normalizePhone($phone) ?: $phone;
    }
}
