<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (Contact $record): bool => ContactResource::canDelete($record)),
            RestoreAction::make()
                ->visible(fn (Contact $record): bool => ContactResource::canRestore($record) && $record->trashed()),
            ForceDeleteAction::make()
                ->visible(fn (Contact $record): bool => ContactResource::canForceDelete($record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Contact $record */
        $record->updateFromAdmin($data);

        return $record->refresh();
    }
}
