<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Models\RevenueImport;
use App\Services\RevenueImportService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRevenueImport extends EditRecord
{
    protected static string $resource = RevenueImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->url(fn (): string => RevenueImportResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var RevenueImport $record */
        $record = $this->getRecord();
        if (! RevenueImportResource::importIsEditable($record)) {
            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var RevenueImport $record */
        $record = $this->getRecord()->fresh();
        if (! $record || ! RevenueImportResource::importIsEditable($record)) {
            return;
        }

        // Period / catalog service changes affect matching and double-send checks.
        app(RevenueImportService::class)->rematch($record);
    }

    protected function getRedirectUrl(): string
    {
        return RevenueImportResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
