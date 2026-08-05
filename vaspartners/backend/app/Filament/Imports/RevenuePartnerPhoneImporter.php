<?php

namespace App\Filament\Imports;

use App\Filament\Concerns\QueuesOnImportQueue;
use App\Models\RevenuePartner;
use App\Services\RevenuePartnerPhoneSyncService;
use App\Services\RevenuePartnerResolver;
use App\Support\PhoneNumber;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Update phones on existing revenue partners by Service ID (or Short code).
 * Does not create partners — use Import CSV for that.
 */
class RevenuePartnerPhoneImporter extends Importer
{
    use QueuesOnImportQueue;

    protected static ?string $model = RevenuePartner::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID')
                ->rules(['nullable', 'max:64'])
                ->example('300100200007127')
                ->helperText('Service ID and/or Short code to match an existing partner.'),
            ImportColumn::make('short_code')
                ->label('Short code')
                ->rules(['nullable', 'max:64'])
                ->example('Teleplay0001')
                ->helperText('Optional. Teleplay* values may also be placed in Service ID.'),
            ImportColumn::make('phone')
                ->label('Phone')
                ->requiredMapping()
                ->rules(['nullable', 'max:32'])
                ->example('+251 91 045 3900')
                ->helperText('Local mobile. Blank or NA skips the row.'),
        ];
    }

    public function resolveRecord(): ?RevenuePartner
    {
        $serviceId = RevenuePartnerResolver::normalize($this->data['service_id'] ?? null);
        $shortCode = RevenuePartnerResolver::normalize($this->data['short_code'] ?? null);

        if ($serviceId !== null && $shortCode === null && ! ctype_digit($serviceId)) {
            $shortCode = $serviceId;
            $serviceId = null;
            $this->data['short_code'] = $shortCode;
            $this->data['service_id'] = null;
        }

        $raw = trim((string) ($this->data['phone'] ?? ''));
        if ($raw === '' || strtoupper($raw) === 'NA') {
            return null;
        }

        $phone = PhoneNumber::normalizeNullable($raw);
        if ($phone === null || ! PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Phone must be a local mobile (9/7 + 8 digits).',
            ]);
        }
        $this->data['phone'] = $phone;

        $partner = app(RevenuePartnerPhoneSyncService::class)
            ->findPartner($serviceId, $shortCode);

        if (! $partner) {
            throw ValidationException::withMessages([
                'service_id' => 'No revenue partner found for this Service ID / Short code.',
            ]);
        }

        return $partner;
    }

    public function fillRecord(): void
    {
        // Only touch phone — never overwrite finance name / ownership.
        if (array_key_exists('phone', $this->data) && filled($this->data['phone'])) {
            $this->record->phone = $this->data['phone'];
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Phone sync finished: '.Number::format($import->successful_rows).' '
            .str('row')->plural($import->successful_rows).' updated.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }
}
