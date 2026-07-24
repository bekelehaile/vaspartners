<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\ThrottlesAuthByIdentifier;
use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Services\AdminPasswordOtpService;
use App\Support\AdminLoginResolver;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ResetPassword extends BaseResetPassword
{
    use ThrottlesAuthByIdentifier;

    private const RATE_LIMIT_PREFIX = 'filament-password-reset-verify';

    private const RATE_LIMIT_MAX_ATTEMPTS = 5;

    private const RATE_LIMIT_DECAY_SECONDS = 60;

    /**
     * Bypass Filament email/token broker checks — OTP is the credential.
     */
    public function mount(?string $email = null, ?string $token = null): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->email = $email ?? request()->query('email');

        $this->form->fill([
            'email' => $this->email,
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Reset password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Reset password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Enter the OTP sent to your phone.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getOtpFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getOtpFormComponent(): Component
    {
        return TextInput::make('otp')
            ->label('OTP')
            ->required()
            ->numeric()
            ->minLength(6)
            ->maxLength(6)
            ->autocomplete('one-time-code')
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/password-reset/reset-password.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(PasswordRule::default())
            ->same('passwordConfirmation')
            ->validationAttribute(__('filament-panels::auth/pages/password-reset/reset-password.form.password.validation_attribute'));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('filament-panels::auth/pages/password-reset/reset-password.form.password_confirmation.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $data = $this->form->getState();

        $this->ensureNotAuthThrottled(
            self::RATE_LIMIT_PREFIX,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            'data.otp',
        );

        try {
            $otpService = app(AdminPasswordOtpService::class);
            $otpRecord = $otpService->findValidRecord((string) $data['otp']);

            if (! $otpRecord) {
                $this->hitAuthThrottle(self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_DECAY_SECONDS);

                throw ValidationException::withMessages([
                    'data.otp' => 'Invalid or expired OTP.',
                ]);
            }

            $user = User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($otpRecord) {
                    $query->where('email', $this->email)
                        ->orWhere(function ($phoneQuery) use ($otpRecord) {
                            $candidates = array_values(array_unique(array_filter([
                                $otpRecord->phone_number,
                                '0'.$otpRecord->phone_number,
                                '251'.$otpRecord->phone_number,
                                '+251'.$otpRecord->phone_number,
                            ])));
                            $phoneQuery->whereIn('phone', $candidates);
                        });
                })
                ->first();

            if (! $user) {
                // Fallback: resolve by OTP phone only
                $user = AdminLoginResolver::resolve((string) $otpRecord->phone_number);
            }

            if (! $user instanceof User || ! $user->is_active) {
                $this->hitAuthThrottle(self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_DECAY_SECONDS);

                throw ValidationException::withMessages([
                    'data.otp' => 'User not found.',
                ]);
            }

            if (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                $this->hitAuthThrottle(self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_DECAY_SECONDS);

                throw ValidationException::withMessages([
                    'data.otp' => 'User not found.',
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ])->save();

            $otpService->deleteByCode((string) $data['otp']);

            $this->clearAuthThrottle(self::RATE_LIMIT_PREFIX);

            event(new PasswordReset($user));

            Filament::auth()->login($user);

            if (request()->hasSession()) {
                request()->session()->regenerate();
                request()->session()->put([
                    'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
                ]);
            }

            try {
                if (filled($user->phone)) {
                    SendSmsJob::dispatch(
                        app(\App\Services\SmsService::class)->normalizePhone($user->phone),
                        'Your VAS Partners admin password was changed. If you did not do this, contact support immediately. Ethio telecom',
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch password-change SMS', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->form->fill();

            Notification::make()
                ->title('Your password has been successfully changed.')
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Password reset failed', [
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('An error occurred. Please try again later.')
                ->danger()
                ->send();

            return null;
        }
    }

    public function getResetPasswordFormAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset password')
            ->submit('resetPassword');
    }

    protected function authRateLimitIdentifier(): string
    {
        return Str::transliterate(Str::lower((string) ($this->email ?? '')));
    }
}
