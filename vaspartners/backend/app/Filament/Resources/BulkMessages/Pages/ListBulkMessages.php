<?php

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Services\BulkMessageService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBulkMessages extends ListRecords
{
    protected static string $resource = BulkMessageResource::class;

    public function getTitle(): string
    {
        return 'Bulk messages';
    }

    public function getSubheading(): ?string
    {
        return 'Import company phones from Excel/CSV (last 9 digits), send SMS, and re-send failures.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (BulkMessageService $bulkMessages): StreamedResponse {
                    $csv = $bulkMessages->templateCsv();

                    return response()->streamDownload(
                        function () use ($csv): void {
                            echo $csv;
                        },
                        'bulk-message-template.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
            Action::make('import')
                ->label('Import & compose')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(BulkMessageResource::getUrl('import')),
        ];
    }
}
