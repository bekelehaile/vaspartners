<?php

namespace App\Filament\Imports;

use App\Enums\RevenueServiceFamily;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

class RevenuePartnerImporter extends Importer
{
    protected static ?string $model = RevenuePartner::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID')
                ->requiredMapping()
                ->rules(['required', 'max:64'])
                ->example('0042822000002838'),
            ImportColumn::make('short_code')
                ->label('Short code')
                ->rules(['nullable', 'max:64'])
                ->example('8100'),
            ImportColumn::make('service_type')
                ->label('Service type')
                ->rules(['nullable', 'max:120'])
                ->example('API'),
            ImportColumn::make('partner_name')
                ->label('Partner name')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->example('FANA BROADCASTING CORPORATE S.C'),
            ImportColumn::make('phone')
                ->label('Phone')
                ->rules(['nullable', 'max:32'])
                ->example('911223344'),
            ImportColumn::make('is_active')
                ->label('Status (active)')
                ->boolean()
                ->rules(['nullable', 'boolean'])
                ->example('1'),
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
                ->label('Product family')
                ->options($options)
                ->required()
                ->native(false)
                ->helperText('All imported master rows are tagged with this family.'),
        ];
    }

    public function resolveRecord(): ?RevenuePartner
    {
        $serviceId = trim((string) ($this->data['service_id'] ?? ''));
        if ($serviceId === '') {
            return null;
        }

        return RevenuePartner::query()->firstOrNew([
            'service_id' => $serviceId,
        ]);
    }

    protected function beforeValidate(): void
    {
        $family = (string) ($this->options['service_family'] ?? '');
        /** @var User|null $user */
        $user = auth()->user() ?? $this->import->user;
        if ($user instanceof User && ! $user->canAccessAllRevenue() && ! $user->managesRevenueFamily($family)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_family' => 'You are not assigned to this product family.',
            ]);
        }
    }

    protected function beforeFill(): void
    {
        if (array_key_exists('phone', $this->data)) {
            $this->data['phone'] = PhoneNumber::normalizeNullable($this->data['phone'] ?? null);
        }

        if (! array_key_exists('is_active', $this->data) || $this->data['is_active'] === null) {
            $this->data['is_active'] = true;
        }

        $this->data['service_family'] = $this->options['service_family'] ?? null;
    }

    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();
        $rules['service_family'] = ['nullable', Rule::in(array_keys(RevenueServiceFamily::options()))];

        return $rules;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Imported '.Number::format($import->successful_rows).' '.str('revenue partner')->plural($import->successful_rows).'.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }
}
