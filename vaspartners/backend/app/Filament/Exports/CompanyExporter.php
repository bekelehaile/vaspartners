<?php

namespace App\Filament\Exports;

use App\Filament\Concerns\QueuesOnExportQueue;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class CompanyExporter extends Exporter
{
    use QueuesOnExportQueue;

    protected static ?string $model = Company::class;

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'ownerMembership.contact',
        ])->withCount('memberships');
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('public_id')
                ->label('ID')
                ->enabledByDefault(false),
            ExportColumn::make('name')
                ->label('Company name'),
            ExportColumn::make('legal_name')
                ->label('ERCA legal name'),
            ExportColumn::make('tin')
                ->label('TIN number'),
            ExportColumn::make('tin_verified')
                ->label('TIN number verified')
                ->state(fn (Company $record): string => $record->isTinValidated() ? 'Yes' : 'No'),
            ExportColumn::make('erca_name_status')
                ->label('Name match')
                ->formatStateUsing(fn (mixed $state): string => CompanyResource::ercaNameMatchLabel($state)),
            ExportColumn::make('erca_name_status_detail')
                ->label('Name match detail')
                ->state(fn (Company $record): ?string => CompanyResource::ercaNameStatusDetail($record->erca_name_status))
                ->enabledByDefault(false),
            ExportColumn::make('claim_phone')
                ->label('Claim phone')
                ->state(fn (Company $record): ?string => $record->claimPhone()),
            ExportColumn::make('erca_phone')
                ->label('ERCA phone')
                ->state(fn (Company $record): ?string => $record->ercaPhone()),
            ExportColumn::make('revenue_phone')
                ->label('Revenue phone')
                ->state(fn (Company $record): ?string => $record->revenuePhone()),
            ExportColumn::make('email')
                ->label('Email'),
            ExportColumn::make('address')
                ->label('Address')
                ->enabledByDefault(false),
            ExportColumn::make('license_valid_until')
                ->label('License valid until'),
            ExportColumn::make('is_active')
                ->label('Active')
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
            ExportColumn::make('owner_name')
                ->label('Owner')
                ->state(fn (Company $record): ?string => $record->ownerMembership?->contact?->name
                    ?? $record->ownerContact()?->name),
            ExportColumn::make('memberships_count')
                ->label('Members'),
            ExportColumn::make('erca_verified_at')
                ->label('ERCA verified at'),
            ExportColumn::make('legacy_mvas_id')
                ->label('Legacy MVAS ID')
                ->enabledByDefault(false),
            ExportColumn::make('created_at')
                ->label('Created at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your company export has completed and '
            .Number::format($export->successful_rows).' '
            .str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '
                .str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
