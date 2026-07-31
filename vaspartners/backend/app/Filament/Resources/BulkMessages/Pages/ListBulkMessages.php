<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Filament\Resources\BulkMessages\BulkMessageResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBulkMessages extends ListRecords
{
    protected static string $resource = BulkMessageResource::class;

    public function getTitle(): string
    {
        return 'Bulk messages';
    }

    public function getSubheading(): ?string
    {
        return 'Special bulk SMS only — compose from companies or import a phone list. Monthly revenue collection is under Monthly revenue.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compose')
                ->label('Compose from companies')
                ->icon('heroicon-o-building-office-2')
                ->url(BulkMessageResource::getUrl('compose')),
            Action::make('import')
                ->label('Import special list')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(BulkMessageResource::getUrl('import')),
        ];
    }
}
