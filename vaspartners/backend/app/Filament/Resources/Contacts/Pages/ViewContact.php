<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => ContactResource::canEdit($this->getRecord())),
            Action::make('toggle_active')
                ->label(fn (Contact $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                ->icon(fn (Contact $record): string => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn (Contact $record): string => $record->is_active ? 'warning' : 'success')
                ->visible(fn (): bool => ContactResource::canEditContacts())
                ->requiresConfirmation()
                ->action(function (Contact $record): void {
                    if (! ContactResource::canEditContacts()) {
                        return;
                    }

                    $record->updateFromAdmin(['is_active' => ! $record->is_active]);

                    Notification::make()
                        ->title($record->is_active ? 'Contact activated' : 'Contact deactivated')
                        ->success()
                        ->send();
                }),
        ];
    }
}
