<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;
use STS\FilamentImpersonate\Actions\Impersonate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTemporaryPassword')
                ->label('Send temporary password')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reset temporary password?')
                ->modalDescription('Generates a new temporary password, requires change on next login, and sends username + password by SMS.')
                ->action(function (): void {
                    $plain = Str::password(10);
                    $this->record->forceFill([
                        'password' => $plain,
                        'must_change_password' => true,
                    ])->save();

                    $phone = $this->record->phone;
                    if (! filled($phone)) {
                        Notification::make()
                            ->title('Password reset locally')
                            ->body('Temporary password set, but the user has no phone for SMS.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $panel = Filament::getPanel('admin');
                    $url = $panel?->getUrl() ?: url('/admin');
                    $username = $this->record->username ?: $this->record->email;
                    $message = "Dear {$this->record->name}, your VAS Partners admin password was reset.\n"
                        ."Username: {$username}\n"
                        ."Temporary password: {$plain}\n"
                        ."Login: {$url}\n"
                        .'You must change this password on next login. Ethio telecom';

                    app(SmsService::class)->send($phone, $message);

                    Notification::make()
                        ->title('Temporary password sent')
                        ->body('Username and temporary password were sent by SMS.')
                        ->success()
                        ->send();
                }),
            Impersonate::make()
                ->record($this->getRecord())
                ->redirectTo(filament()->getCurrentOrDefaultPanel()?->getUrl() ?? '/admin'),
            DeleteAction::make(),
        ];
    }
}
