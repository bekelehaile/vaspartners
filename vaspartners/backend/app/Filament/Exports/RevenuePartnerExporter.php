<?php

namespace App\Filament\Exports;

use App\Filament\Concerns\QueuesOnExportQueue;
use App\Models\RevenuePartner;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class RevenuePartnerExporter extends Exporter
{
    use QueuesOnExportQueue;

    protected static ?string $model = RevenuePartner::class;

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['vasService', 'company', 'creator']);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('public_id')
                ->label('ID')
                ->enabledByDefault(false),
            ExportColumn::make('partner_name')
                ->label('Partner name'),
            ExportColumn::make('company.name')
                ->label('Company')
                ->state(fn (RevenuePartner $record): ?string => $record->company?->name),
            ExportColumn::make('phone')
                ->label('Phone'),
            ExportColumn::make('vasService.name')
                ->label('Catalog service')
                ->state(fn (RevenuePartner $record): ?string => $record->vasService?->name),
            ExportColumn::make('service_id')
                ->label('Service ID'),
            ExportColumn::make('product_id')
                ->label('Product ID'),
            ExportColumn::make('spid')
                ->label('SPID'),
            ExportColumn::make('short_code')
                ->label('Short code'),
            ExportColumn::make('creator.name')
                ->label('Account manager')
                ->state(fn (RevenuePartner $record): ?string => $record->creator?->name),
            ExportColumn::make('is_active')
                ->label('Active')
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
            ExportColumn::make('notes')
                ->label('Notes')
                ->enabledByDefault(false),
            ExportColumn::make('created_at')
                ->label('Created at'),
            ExportColumn::make('updated_at')
                ->label('Updated at')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your revenue partner export has completed and '
            .Number::format($export->successful_rows).' '
            .str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '
                .str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
