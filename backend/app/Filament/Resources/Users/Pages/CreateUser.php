<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\SmsService;
use Filament\Facades\Filament;
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
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    protected function afterCreate(): void
    {
        $phone = $this->record->phone;
        if (! filled($phone) || ! filled($this->plainPassword)) {
            return;
        }

        $panel = Filament::getPanel('admin');
        $url = $panel?->getUrl() ?: url('/admin');
        $message = "Dear {$this->record->name}, your VAS Partners admin account is ready.\n"
            ."Email: {$this->record->email}\n"
            ."Password: {$this->plainPassword}\n"
            ."Login: {$url}\n"
            .'Ethio telecom';

        app(SmsService::class)->send($phone, $message);
    }
}
