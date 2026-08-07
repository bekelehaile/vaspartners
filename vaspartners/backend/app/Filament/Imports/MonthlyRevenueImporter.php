<?php

namespace App\Filament\Imports;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Concerns\QueuesOnImportQueue;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\Service;
use App\Models\User;
use App\Services\RevenueImportService;
use App\Services\RevenuePartnerResolver;
use App\Support\RevenueCatalogServices;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Monthly revenue CSV: Service ID + amount (short code optional).
 * Partner name and phone come from the AM's Revenue Partners list.
 */
class MonthlyRevenueImporter extends Importer
{
    use QueuesOnImportQueue;

    protected static ?string $model = RevenueImportRow::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID')
                ->requiredMapping()
                ->rules(['required', 'max:64'])
                ->example('601654')
                ->helperText('Required. Matched to your Revenue Partners list.')
                ->guess(['service id', 'serviceid', 'sid', 'sp code']),
            ImportColumn::make('short_code')
                ->label('Short code')
                ->rules(['nullable', 'max:64'])
                ->example('8100')
                ->helperText('Optional.')
                ->guess(['short code', 'shortcode']),
            ImportColumn::make('revenue')
                ->label('Amount')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'gt:0'])
                ->example('5909566.82')
                ->helperText('Required. From the finance file.')
                ->guess(['revenue', 'amount', 'partner share']),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            RevenueCatalogServices::importSelect(
                'Used in the SMS message. Matching still uses your Revenue Partners list.',
            ),
            Select::make('period')
                ->label('Month')
                ->options(fn (): array => static::monthOptions())
                ->required()
                ->searchable()
                ->native(false)
                ->default(fn (): string => now()->format('F Y')),
            Textarea::make('message_template')
                ->label('SMS message')
                ->rows(4)
                ->maxLength(640)
                ->default(RevenueImportService::DEFAULT_SMS_TEMPLATE)
                ->helperText('{company_name}, {period}, {service_type}, {service_id}, {amount}'),
        ];
    }

    /**
     * Month labels stored on revenue_imports.period (e.g. "April 2026").
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $options = [];
        $cursor = Carbon::now()->startOfMonth()->subYears(4);
        $end = Carbon::now()->startOfMonth()->addYear();

        while ($cursor->lte($end)) {
            $label = $cursor->format('F Y');
            $options[$label] = $label;
            $cursor->addMonth();
        }

        return array_reverse($options, true);
    }

    public function resolveRecord(): ?RevenueImportRow
    {
        $batch = $this->ensureBatch();

        return new RevenueImportRow([
            'revenue_import_id' => $batch->id,
            'vas_service_id' => $batch->vas_service_id,
            'row_number' => null,
        ]);
    }

    protected function beforeValidate(): void
    {
        $serviceId = RevenuePartnerResolver::normalize($this->data['service_id'] ?? null);
        $shortCode = RevenuePartnerResolver::normalize($this->data['short_code'] ?? null);
        $this->data['service_id'] = $serviceId;
        $this->data['short_code'] = $shortCode;

        // Catalog service is chosen on the import options form, not in the CSV.
        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0);
        $this->data['vas_service_id'] = $vasServiceId > 0 ? $vasServiceId : null;

        if ($serviceId === null) {
            throw ValidationException::withMessages([
                'service_id' => 'Service ID is required.',
            ]);
        }
    }

    protected function beforeFill(): void
    {
        $ownerUserId = $this->ownerUserIdForMatch();
        $resolver = app(RevenuePartnerResolver::class);
        $lookup = $resolver->resolve(
            $this->data['service_id'] ?? null,
            $this->data['short_code'] ?? null,
            $ownerUserId,
        );

        $revenue = $this->data['revenue'] ?? null;
        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0) ?: null;
        $amount = is_numeric($revenue) ? round((float) $revenue, 4) : null;
        $period = trim((string) ($this->options['period'] ?? ''));

        $this->data['amount'] = $amount;
        $this->data['amount_raw'] = $revenue !== null ? (string) $revenue : null;
        $this->data['service_id'] = $lookup['service_id'];
        $this->data['short_code'] = $lookup['short_code'];
        $this->data['vas_service_id'] = $vasServiceId;

        if (! $lookup['ok']) {
            $this->data['revenue_partner_id'] = null;
            $this->data['partner_name'] = null;
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = $lookup['error'];
            unset($this->data['revenue']);

            return;
        }

        $partner = $lookup['partner'];

        if (! $partner) {
            $this->data['revenue_partner_id'] = null;
            $this->data['partner_name'] = null;
            $this->data['status'] = RevenueImportRowStatus::MissingPartner->value;
            $this->data['error'] = 'Not found in your Revenue Partners list.';
        } elseif (! $partner->is_active) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = 'Partner is inactive.';
        } elseif (! $partner->hasUsablePhone()) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::MissingPhone->value;
            $this->data['error'] = 'Partner phone is missing or invalid.';
        } elseif ($period !== '' && app(RevenueImportService::class)->wouldDoubleSend(
            $partner,
            $period,
            null,
            [
                'amount' => $amount,
                'am_user_id' => $this->import->user_id ? (int) $this->import->user_id : null,
                'catalog_service_id' => $vasServiceId,
            ],
        )) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::Duplicate->value;
            $this->data['error'] = "SMS already sent for this partner in {$period}.";
        } else {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::Matched->value;
            $this->data['error'] = null;
        }

        unset($this->data['revenue']);
    }

    protected function applyPartnerSnapshot(RevenuePartner $partner): void
    {
        $this->data['revenue_partner_id'] = $partner->id;
        $this->data['partner_name'] = $partner->partner_name;
        $this->data['service_id'] = $partner->service_id;
        $this->data['short_code'] = RevenuePartnerResolver::normalize($partner->short_code)
            ?? RevenuePartnerResolver::normalize($this->data['short_code'] ?? null);
        $this->data['vas_service_id'] = $this->data['vas_service_id'] ?? $partner->vas_service_id;
    }

    /**
     * AMs match only their partners; admins match the full master list.
     */
    protected function ownerUserIdForMatch(): ?int
    {
        $userId = (int) ($this->import->user_id ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);
        if ($user?->canAccessAllRevenue()) {
            return null;
        }

        return $userId;
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
            'status' => $this->data['status'] ?? RevenueImportRowStatus::Invalid->value,
            'error' => $this->data['error'] ?? null,
            'vas_service_id' => $this->data['vas_service_id'] ?? $this->record->vas_service_id,
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
        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0);
        if ($vasServiceId <= 0) {
            throw ValidationException::withMessages([
                'vas_service_id' => 'Select a catalog service before importing.',
            ]);
        }

        $period = trim((string) ($this->options['period'] ?? ''));
        $template = trim((string) ($this->options['message_template'] ?? RevenueImportService::DEFAULT_SMS_TEMPLATE));
        $service = Service::query()->find($vasServiceId);

        return RevenueImport::query()->firstOrCreate(
            ['filament_import_id' => $this->import->getKey()],
            [
                'title' => ($service?->name ?? 'Revenue').' — '.$period,
                'period' => $period !== '' ? $period : 'Unknown',
                'vas_service_id' => $vasServiceId,
                'source_filename' => $this->import->file_name,
                'status' => RevenueImportStatus::Draft->value,
                'message_template' => $template !== '' ? $template : RevenueImportService::DEFAULT_SMS_TEMPLATE,
                'created_by_user_id' => $this->import->user_id,
                'imported_at' => now(),
            ],
        );
    }

    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();
        $rules['vas_service_id'] = ['required', 'integer', Rule::exists('services', 'id')];

        return $rules;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $batch = RevenueImport::query()->where('filament_import_id', $import->getKey())->first();
        $batch?->resolveStatusFromRows();

        $body = 'Imported '.Number::format($import->successful_rows).' monthly revenue '.str('row')->plural($import->successful_rows).'.';
        if ($batch) {
            $body .= " Ready {$batch->matched_count}, unresolved {$batch->missing_partner_count}, missing phone {$batch->missing_phone_count}.";
        }
        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }
}
