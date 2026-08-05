<?php

namespace App\Filament\Exports;

use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Filament\Concerns\QueuesOnExportQueue;
use App\Models\Ticket;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class TicketExporter extends Exporter
{
    use QueuesOnExportQueue;

    protected static ?string $model = Ticket::class;

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'subscription.company',
            'contact.company',
            'service',
            'category',
            'requisition',
            'assignee',
            'currentApprover',
        ]);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('tt_number')
                ->label('Request number'),
            ExportColumn::make('company_name')
                ->label('Company')
                ->state(fn (Ticket $record): ?string => $record->subscription?->company?->name
                    ?? $record->contact?->company?->name
                    ?? $record->contact?->company_name),
            ExportColumn::make('company_tin')
                ->label('TIN number')
                ->state(fn (Ticket $record): ?string => $record->subscription?->company?->tin
                    ?? $record->contact?->company?->tin
                    ?? $record->contact?->company_tin),
            ExportColumn::make('contact.name')
                ->label('Contact'),
            ExportColumn::make('contact.phone_number')
                ->label('Phone'),
            ExportColumn::make('contact.email')
                ->label('Email'),
            ExportColumn::make('service.name')
                ->label('Service'),
            ExportColumn::make('subscription.status')
                ->label('Subscription')
                ->formatStateUsing(function (mixed $state): string {
                    if ($state instanceof SubscriptionStatus) {
                        return $state->label();
                    }

                    return SubscriptionStatus::tryLabel((string) $state) ?: (string) ($state ?? '');
                }),
            ExportColumn::make('category.name')
                ->label('Group'),
            ExportColumn::make('requisition.name')
                ->label('Request type'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(function (mixed $state): string {
                    if ($state instanceof TicketStatus) {
                        return $state->label();
                    }

                    return TicketStatus::tryFrom((string) $state)?->label() ?? (string) ($state ?? '');
                }),
            ExportColumn::make('document_review_status')
                ->label('Doc review')
                ->formatStateUsing(function (mixed $state, Ticket $record): string {
                    return $record->documentReviewLabel();
                }),
            ExportColumn::make('assignee.name')
                ->label('Account manager'),
            ExportColumn::make('currentApprover.name')
                ->label('Approver'),
            ExportColumn::make('created_at')
                ->label('Created at'),
            ExportColumn::make('assigned_at')
                ->label('Assigned at'),
            ExportColumn::make('completed_at')
                ->label('Completed at'),
            ExportColumn::make('closed_at')
                ->label('Closed at'),
            ExportColumn::make('description')
                ->label('Description')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your ticket export has completed and '
            .Number::format($export->successful_rows).' '
            .str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '
                .str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
};
