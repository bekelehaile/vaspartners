<?php

namespace App\Filament\Imports;

use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Models\RevenueImport;
use App\Models\RevenueImportRow;
use App\Models\RevenuePartner;
use App\Models\Service;
use App\Models\User;
use App\Services\BulkMessageService;
use App\Services\RevenuePartnerResolver;
use App\Support\RevenueCatalogServices;
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
 * Cleaned monthly revenue CSV: service_id + revenue (short_code optional).
 * Import is scoped to an existing catalog service; unresolved rows are flagged for AM edit.
 */
class MonthlyRevenueImporter extends Importer
{
    protected static ?string $model = RevenueImportRow::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID (billing)')
                ->requiredMapping()
                ->rules(['required', 'max:64'])
                ->guess(['service id', 'serviceid', 'sid', 'sp code']),
            ImportColumn::make('short_code')
                ->label('Short code (optional)')
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

        return [
            Select::make('vas_service_id')
                ->label('Catalog service')
                ->options(RevenueCatalogServices::options($user))
                ->required()
                ->searchable()
                ->native(false)
                ->helperText('Must be an existing portal service. Partners are matched only within this service.'),
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

        if ($serviceId === null) {
            throw ValidationException::withMessages([
                'service_id' => 'Billing service ID is required.',
            ]);
        }

        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0);
        /** @var User|null $user */
        $user = $this->import->user;
        if ($user instanceof User && ! $user->managesRevenueService($vasServiceId)) {
            throw ValidationException::withMessages([
                'vas_service_id' => 'You are not assigned to this catalog service.',
            ]);
        }
    }

    protected function beforeFill(): void
    {
        $resolver = app(RevenuePartnerResolver::class);
        $lookup = $resolver->resolve(
            $this->data['service_id'] ?? null,
            $this->data['short_code'] ?? null,
        );

        $revenue = $this->data['revenue'] ?? null;
        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0) ?: null;
        $amount = is_numeric($revenue) ? round((float) $revenue, 4) : null;

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
            $this->data['error'] = 'Unresolved: not in master list for this catalog service. Edit this row or add the partner, then Rematch.';
        } elseif ($vasServiceId && (int) $partner->vas_service_id !== $vasServiceId) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = 'Master partner is mapped to a different catalog service.';
        } elseif (! $partner->is_active) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::Invalid->value;
            $this->data['error'] = 'Partner status is inactive.';
        } elseif (! $partner->hasUsablePhone()) {
            $this->applyPartnerSnapshot($partner);
            $this->data['status'] = RevenueImportRowStatus::MissingPhone->value;
            $this->data['error'] = 'Master list phone is empty or invalid.';
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
        $this->data['vas_service_id'] = $partner->vas_service_id;
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
        $period = trim((string) ($this->options['period'] ?? ''));
        $template = trim((string) ($this->options['message_template'] ?? BulkMessageService::DEFAULT_MESSAGE));
        $service = Service::query()->find($vasServiceId);

        return RevenueImport::query()->firstOrCreate(
            ['filament_import_id' => $this->import->getKey()],
            [
                'title' => ($service?->name ?? 'Revenue').' — '.$period,
                'period' => $period !== '' ? $period : 'Unknown',
                'vas_service_id' => $vasServiceId,
                'source_filename' => $this->import->file_name,
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
