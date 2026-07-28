<?php

namespace App\Filament\Imports;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Enums\RevenueServiceFamily;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Services\BulkMessageService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single-sheet cleaned monthly revenue CSV.
 * Columns: service_id, short_code, revenue.
 * Master list supplies partner name / phone / service type.
 */
class MonthlyRevenueImporter extends Importer
{
    protected static ?string $model = RevenueImportRow::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID')
                ->rules(['nullable', 'max:64'])
                ->example('0042822000002838')
                ->guess(['service id', 'serviceid', 'sid', 'sp code']),
            ImportColumn::make('short_code')
                ->label('Short code')
                ->rules(['nullable', 'max:64'])
                ->example('8100')
                ->guess(['short code', 'shortcode']),
            ImportColumn::make('revenue')
                ->label('Revenue')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'gt:0'])
                ->example('5909566.82')
                ->guess(['revenue', 'amount', 'partner share']),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        $options = RevenueServiceFamily::options();
        if ($user && ! $user->canAccessAllRevenue()) {
            $allowed = $user->managedRevenueFamilyValues();
            $options = array_intersect_key($options, array_flip($allowed));
        }

        return [
            Select::make('service_family')
                ->label('Service (product family)')
                ->options($options)
                ->required()
                ->native(false),
            TextInput::make('period')
                ->label('Month')
                ->required()
                ->maxLength(64)
                ->placeholder('April 2026'),
            Textarea::make('message_template')
                ->label('SMS template')
                ->rows(4)
                ->maxLength(640)
                ->default(BulkMessageService::DEFAULT_MESSAGE)
                ->helperText('{company_name} {period} {service_type} {service_id} {amount}'),
        ];
    }

    public function resolveRecord(): ?RevenueImportRow
    {
        $batch = $this->ensureBatch();

        return new RevenueImportRow([
            'revenue_import_id' => $batch->id,
            'service_family' => $batch->service_family?->value ?? $this->options['service_family'] ?? null,
            'sheet_name' => $batch->service_family instanceof RevenueServiceFamily
                ? $batch->service_family->label()
                : (string) ($this->options['service_family'] ?? 'Monthly'),
            'row_number' => null,
        ]);
    }

    protected function beforeValidate(): void
    {
        $serviceId = trim((string) ($this->data['service_id'] ?? ''));
        $shortCode = trim((string) ($this->data['short_code'] ?? ''));
        if ($serviceId === '' && $shortCode === '') {
            throw ValidationException::withMessages([
                'service_id' => 'Provide service_id and/or short_code.',
            ]);
        }

        $family = (string) ($this->options['service_family'] ?? '');
        /** @var User|null $user */
        $user = $this->import->user;
        if ($user instanceof User && ! $user->canAccessAllRevenue() && ! $user->managesRevenueFamily($family)) {
            throw ValidationException::withMessages([
                'service_family' => 'You are not assigned to this product family.',
            ]);
        }
    }

    protected function beforeFill(): void
    {
        $serviceId = trim((string) ($this->data['service_id'] ?? ''));
        $shortCode = trim((string) ($this->data['short_code'] ?? ''));
        $revenue = $this->data['revenue'] ?? null;

        $partner = null;
        if ($serviceId !== '') {
            $partner = RevenuePartner::query()->where('service_id', $serviceId)->first();
        }
        if (! $partner && $shortCode !== '') {
            $partner = RevenuePartner::query()->where('short_code', $shortCode)->first();
        }

        $family = RevenueServiceFamily::tryFrom((string) ($this->options['service_family'] ?? ''));
        $amount = is_numeric($revenue) ? round((float) $revenue, 4) : null;

        $this->data['amount'] = $amount;
        $this->data['amount_raw'] = $revenue !== null ? (string) $revenue : null;
        $this->data['short_code'] = $shortCode !== '' ? $shortCode : null;
        $this->data['service_id'] = $serviceId !== '' ? $serviceId : ($partner?->service_id ?: $shortCode);

        if (! $partner) {
            $this->data['revenue_partner_id'] = null;
            $this->data['partner_name'] = null;
            $this->data['service_type'] = null;
            $this->data['status'] = RevenueImportRowStatus::MissingPartner->value;
            $this->data['error'] = 'Not in revenue partners master list.';
        } elseif ($family && $partner->service_family && $partner->service_family !== $family) {
            $this->data['revenue_partner_id'] = $partner->id;
            $this->data['partner_name'] = $partner->partner_name;
            $this->data['service_type'] = $partner->service_type;
            $this->data['service_id'] = $partner->service_id;
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = 'Master family does not match this import.';
        } elseif (! $partner->is_active) {
            $this->data['revenue_partner_id'] = $partner->id;
            $this->data['partner_name'] = $partner->partner_name;
            $this->data['service_type'] = $partner->service_type;
            $this->data['service_id'] = $partner->service_id;
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = 'Partner status is inactive.';
        } elseif (! $partner->hasUsablePhone()) {
            $this->data['revenue_partner_id'] = $partner->id;
            $this->data['partner_name'] = $partner->partner_name;
            $this->data['service_type'] = $partner->service_type;
            $this->data['service_id'] = $partner->service_id;
            $this->data['status'] = RevenueImportRowStatus::MissingPhone->value;
            $this->data['error'] = 'Master list phone is empty or invalid.';
        } else {
            $this->data['revenue_partner_id'] = $partner->id;
            $this->data['partner_name'] = $partner->partner_name;
            $this->data['service_type'] = $partner->service_type;
            $this->data['service_id'] = $partner->service_id;
            $this->data['status'] = RevenueImportRowStatus::Matched->value;
            $this->data['error'] = null;
        }

        // Drop CSV-only key so fillRecord does not try to set a missing attribute incorrectly.
        unset($this->data['revenue']);
    }

    protected function afterFill(): void
    {
        if (! $this->record instanceof RevenueImportRow) {
            return;
        }

        $this->record->forceFill([
            'service_id' => $this->data['service_id'] ?? null,
            'short_code' => $this->data['short_code'] ?? null,
            'amount' => $this->data['amount'] ?? null,
            'amount_raw' => $this->data['amount_raw'] ?? null,
            'revenue_partner_id' => $this->data['revenue_partner_id'] ?? null,
            'partner_name' => $this->data['partner_name'] ?? null,
            'service_type' => $this->data['service_type'] ?? null,
            'status' => $this->data['status'] ?? RevenueImportRowStatus::Invalid->value,
            'error' => $this->data['error'] ?? null,
            'service_family' => $this->options['service_family'] ?? $this->record->service_family,
        ]);
    }

    protected function afterSave(): void
    {
        $batch = RevenueImport::query()
            ->where('filament_import_id', $this->import->getKey())
            ->first();
        $batch?->resolveStatusFromRows();
    }

    protected function ensureBatch(): RevenueImport
    {
        $family = (string) ($this->options['service_family'] ?? '');
        $period = trim((string) ($this->options['period'] ?? ''));
        $template = trim((string) ($this->options['message_template'] ?? BulkMessageService::DEFAULT_MESSAGE));
        $familyEnum = RevenueServiceFamily::tryFrom($family);

        return RevenueImport::query()->firstOrCreate(
            ['filament_import_id' => $this->import->getKey()],
            [
                'title' => ($familyEnum?->label() ?? 'Revenue').' — '.$period,
                'period' => $period !== '' ? $period : 'Unknown',
                'service_family' => $family !== '' ? $family : null,
                'source_filename' => $this->import->file_name,
                'source_path' => null,
                'status' => RevenueImportStatus::Draft->value,
                'message_template' => $template !== '' ? $template : BulkMessageService::DEFAULT_MESSAGE,
                'created_by_user_id' => $this->import->user_id,
                'imported_at' => now(),
            ],
        );
    }

    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();
        $rules['service_family'] = ['nullable', Rule::in(array_keys(RevenueServiceFamily::options()))];

        return $rules;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $batch = RevenueImport::query()->where('filament_import_id', $import->getKey())->first();
        $batch?->resolveStatusFromRows();

        $body = 'Imported '.Number::format($import->successful_rows).' monthly revenue '.str('row')->plural($import->successful_rows).'.';
        if ($batch) {
            $body .= " Ready {$batch->matched_count}, missing partner {$batch->missing_partner_count}, missing phone {$batch->missing_phone_count}.";
        }
        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }
}
