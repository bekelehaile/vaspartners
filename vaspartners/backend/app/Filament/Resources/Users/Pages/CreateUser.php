<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\SmsService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?string $plainPassword = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plainPassword = Str::password(10);
        $data['password'] = $this->plainPassword;
        $data['must_change_password'] = true;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    protected function afterCreate(): void
    {
        // Super admin is not assignable from this form.
        if ($this->record->hasRole('super_admin')) {
            $this->record->removeRole('super_admin');
        }

        $sent = $this->sendTemporaryCredentialsSms();

        Notification::make()
            ->title('User created')
            ->body($sent
                ? 'Temporary password sent by SMS. User must change it on first login.'
                : 'User created. SMS could not be sent (missing phone or SMS disabled).')
            ->{$sent ? 'success' : 'warning'}()
            ->send();
    }

    protected function sendTemporaryCredentialsSms(): bool
    {
        $phone = $this->record->phone;
        if (! filled($phone) || ! filled($this->plainPassword)) {
            return false;
        }

        $panel = Filament::getPanel('admin');
        $url = $panel?->getUrl() ?: url('/admin');
        $username = $this->record->username ?: $this->record->email;
        $message = "Dear {$this->record->name}, your VAS Partners admin account is ready.\n"
            ."Username: {$username}\n"
            ."Temporary password: {$this->plainPassword}\n"
            ."Login: {$url}\n"
            .'You must change this password on first login. Ethio telecom';

        try {
            app(SmsService::class)->send($phone, $message);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
