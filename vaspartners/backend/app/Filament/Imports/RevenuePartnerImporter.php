<?php

namespace App\Filament\Imports;

use App\Models\RevenuePartner;
use App\Models\User;
use App\Services\RevenuePartnerResolver;
use App\Support\PhoneNumber;
use App\Support\RevenueCatalogServices;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Each AM imports partners that belong to them.
 * Re-import: service ID / short code are kept when already set; missing ones (and phone) can be filled.
 */
class RevenuePartnerImporter extends Importer
{
    protected static ?string $model = RevenuePartner::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('service_id')
                ->label('Service ID')
                ->rules(['nullable', 'max:64'])
                ->example('0042822000002838')
                ->helperText('Required to create a new partner. On re-import, existing values are kept.'),
            ImportColumn::make('short_code')
                ->label('Short code')
                ->rules(['nullable', 'max:64'])
                ->example('8100')
                ->helperText('Unique. On re-import, filled only when the master record is missing one.'),
            ImportColumn::make('partner_name')
                ->label('Partner name')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->example('FANA BROADCASTING CORPORATE S.C')
                ->helperText('Name from the finance system (Excel).'),
            ImportColumn::make('phone')
                ->label('Phone')
                ->requiredMapping()
                ->rules(['required', 'max:32'])
                ->example('911223344')
                ->ignoreBlankState()
                ->helperText('Required for new partners. On re-import, blank keeps an existing phone.'),
            ImportColumn::make('is_active')
                ->label('Status (active)')
                ->boolean()
                ->rules(['nullable', 'boolean'])
                ->example('1')
                ->ignoreBlankState(),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            RevenueCatalogServices::importSelect(
                'Catalog service for labeling / SMS wording. Ownership is by account manager, not by service.',
            ),
        ];
    }

    public function resolveRecord(): ?RevenuePartner
    {
        $ownerUserId = $this->ownerUserIdForMatch();
        $lookup = app(RevenuePartnerResolver::class)->resolveForUpsert(
            $this->data['service_id'] ?? null,
            $this->data['short_code'] ?? null,
            $ownerUserId,
        );

        if (! $lookup['ok']) {
            throw ValidationException::withMessages([
                'service_id' => $lookup['error'],
                'short_code' => $lookup['error'],
            ]);
        }

        $this->data['service_id'] = $lookup['service_id'];
        $this->data['short_code'] = $lookup['short_code'];

        if ($lookup['partner'] instanceof RevenuePartner) {
            // Claim unowned seed partners for the importing AM.
            if ($ownerUserId
                && ! $lookup['partner']->created_by_user_id
                && $this->import->user_id
            ) {
                $lookup['partner']->forceFill([
                    'created_by_user_id' => $this->import->user_id,
                ])->save();
            }

            return $lookup['partner'];
        }

        return new RevenuePartner([
            'service_id' => $lookup['service_id'],
            'created_by_user_id' => $this->import->user_id,
        ]);
    }

    protected function beforeValidate(): void
    {
        $this->data['service_id'] = RevenuePartnerResolver::normalize($this->data['service_id'] ?? null);
        $this->data['short_code'] = RevenuePartnerResolver::normalize($this->data['short_code'] ?? null);
        $this->data['phone'] = PhoneNumber::normalizeNullable($this->data['phone'] ?? null);

        if ($this->data['service_id'] === null && $this->data['short_code'] === null) {
            throw ValidationException::withMessages([
                'service_id' => 'Provide service ID and/or short code.',
                'short_code' => 'Provide service ID and/or short code.',
            ]);
        }

        $phone = $this->data['phone'];
        if ($this->record?->exists) {
            $effectivePhone = $phone ?? PhoneNumber::normalizeNullable($this->record->phone);
            if ($effectivePhone === null || ! PhoneNumber::isValidLocalMobile($effectivePhone)) {
                throw ValidationException::withMessages([
                    'phone' => 'Phone is required (local mobile 9/7 + 8 digits).',
                ]);
            }
            if ($phone !== null) {
                $this->data['phone'] = $effectivePhone;
            }
        } elseif ($phone === null || ! PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Phone is required (local mobile 9/7 + 8 digits).',
            ]);
        }
    }

    protected function beforeFill(): void
    {
        if (array_key_exists('phone', $this->data)) {
            $this->data['phone'] = PhoneNumber::normalizeNullable($this->data['phone'] ?? null);
        }

        if (! array_key_exists('is_active', $this->data) || $this->data['is_active'] === null) {
            // New records default to active; existing keep their value via ignoreBlankState.
            if (! $this->record?->exists) {
                $this->data['is_active'] = true;
            }
        }

        if ($this->record?->exists) {
            $this->preserveExistingIdentifiersOnUpdate();
        }
    }

    protected function afterFill(): void
    {
        if (! $this->record instanceof RevenuePartner) {
            return;
        }

        $vasServiceId = (int) ($this->options['vas_service_id'] ?? 0) ?: null;
        if ($vasServiceId && ! $this->record->vas_service_id) {
            $this->record->vas_service_id = $vasServiceId;
        }

        if (! $this->record->exists && $this->import->user_id && ! $this->record->created_by_user_id) {
            $this->record->created_by_user_id = $this->import->user_id;
        }
    }

    /**
     * Re-import: never overwrite an existing service ID / short code.
     * Fill only when the master record is missing that field. Phone may update when CSV has a value.
     */
    protected function preserveExistingIdentifiersOnUpdate(): void
    {
        /** @var RevenuePartner $partner */
        $partner = $this->record;

        $existingServiceId = RevenuePartnerResolver::normalize($partner->service_id);
        $existingShortCode = RevenuePartnerResolver::normalize($partner->short_code);
        $incomingServiceId = RevenuePartnerResolver::normalize($this->data['service_id'] ?? null);
        $incomingShortCode = RevenuePartnerResolver::normalize($this->data['short_code'] ?? null);

        if ($existingServiceId !== null) {
            $this->data['service_id'] = $existingServiceId;
        } elseif ($incomingServiceId !== null) {
            $taken = RevenuePartner::query()
                ->where('service_id', $incomingServiceId)
                ->whereKeyNot($partner->getKey())
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'service_id' => "Service ID {$incomingServiceId} is already used by another master partner.",
                ]);
            }
            $this->data['service_id'] = $incomingServiceId;
        } else {
            $this->data['service_id'] = $partner->service_id;
        }

        if ($existingShortCode !== null) {
            $this->data['short_code'] = $existingShortCode;
        } elseif ($incomingShortCode !== null) {
            $taken = RevenuePartner::query()
                ->where('short_code', $incomingShortCode)
                ->whereKeyNot($partner->getKey())
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'short_code' => "Short code {$incomingShortCode} is already used by another master partner.",
                ]);
            }
            $this->data['short_code'] = $incomingShortCode;
        } else {
            // Leave blank state ignored so null CSV does not clear a null field; keep model value.
            unset($this->data['short_code']);
        }

        // Phone: CSV value updates; blank already ignored via ignoreBlankState().
        if (! filled($this->data['phone'] ?? null)) {
            unset($this->data['phone']);
        }
    }

    /**
     * AMs match only their own partners; admins match the full master list.
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

    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();
        $rules['vas_service_id'] = ['required', 'integer', Rule::exists('services', 'id')];
        $rules['service_id'] = ['nullable', 'max:64'];
        $rules['short_code'] = ['nullable', 'max:64'];

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
