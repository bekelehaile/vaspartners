<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SmsService;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Phone or username')
            ->placeholder('e.g. 0930011756 or your username')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $user = $this->resolveUserByLogin((string) ($data['login'] ?? ''));

        return [
            'email' => $user?->email ?? '__invalid__',
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    protected function resolveUserByLogin(string $login): ?User
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $sms = app(SmsService::class);
        $normalizedPhone = $sms->normalizePhone($login);
        $candidates = array_values(array_unique(array_filter([
            $login,
            $normalizedPhone,
            '0'.$normalizedPhone,
            '251'.$normalizedPhone,
            '+251'.$normalizedPhone,
        ])));

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($login, $candidates) {
                $query->whereRaw('LOWER(username) = ?', [mb_strtolower($login)]);

                foreach ($candidates as $candidate) {
                    $query->orWhere('phone', $candidate);
                }
            })
            ->first();
    }
}
