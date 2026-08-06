<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\ServiceOperationalStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionProvisioningLogService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->loadMissing(['service', 'company', 'contact']);
    }

    public function getTitle(): string|Htmlable
    {
        return SubscriptionResource::getRecordTitle($this->getRecord()) ?? 'Subscription';
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $bits = array_filter([
            $record->public_id ? 'ID '.$record->public_id : null,
            $record->company?->name,
        ]);

        return $bits !== []
            ? implode(' · ', $bits)
            : 'Details, linked service requests, messages, attachments, and status logs.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_contract')
                ->label('Record contract details')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Closed)
                ->form(fn (Subscription $record): array => $this->contractFormSchema($record))
                ->fillForm(fn (Subscription $record): array => $this->contractFormDefaults($record))
                ->action(function (Subscription $record, array $data, SubscriptionLifecycleService $lifecycle): void {
                    try {
                        $lifecycle->updateContractDetails($record, $data, auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not save contract details')
                            ->body(collect($e->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();

                        throw $e;
                    }

                    Notification::make()
                        ->title('Contract details saved')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'contract_signed_at',
                        'renewal_year',
                        'vas_license_expires_at',
                    ]);
                }),
            Action::make('close_contract')
                ->label('Close subscription')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (Subscription $record): bool => $record->status->isAlive()
                    || $record->status === SubscriptionStatus::Expired)
                ->modalHeading('Close subscription (contract follow-up)')
                ->modalDescription(fn (Subscription $record): string => $record->requiresVasLicenseExpiry()
                    ? 'Premium service: contract signing date, renewal year, and VAS license expiry are required before closing.'
                    : 'Contract signing date and renewal year are required before closing.')
                ->form(fn (Subscription $record): array => [
                    ...$this->contractFormSchema($record, required: true),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(3),
                ])
                ->fillForm(fn (Subscription $record): array => $this->contractFormDefaults($record))
                ->requiresConfirmation()
                ->action(function (Subscription $record, array $data, SubscriptionLifecycleService $lifecycle): void {
                    try {
                        $lifecycle->closeForContractFollowUp($record, $data, auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot close subscription')
                            ->body(collect($e->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();

                        throw $e;
                    }

                    Notification::make()
                        ->title('Subscription closed')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'closed_at',
                        'contract_signed_at',
                        'renewal_year',
                        'vas_license_expires_at',
                        'next_renewal_due_at',
                    ]);
                }),
            Action::make('set_uptime')
                ->label('Set uptime status')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->form([
                    Select::make('operational_status')
                        ->label('Uptime status')
                        ->options(collect(ServiceOperationalStatus::cases())
                            ->mapWithKeys(fn (ServiceOperationalStatus $s) => [$s->value => $s->label()])
                            ->all())
                        ->required()
                        ->default(fn (Subscription $record): string => ($record->operational_status instanceof ServiceOperationalStatus
                            ? $record->operational_status
                            : ServiceOperationalStatus::tryFrom((string) ($record->operational_status ?? ''))
                        )?->value ?? ServiceOperationalStatus::Unknown->value)
                        ->helperText('Staff-reported until an external probe is connected.'),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(3),
                ])
                ->action(function (Subscription $record, array $data, SubscriptionProvisioningLogService $logs): void {
                    $logs->setOperationalStatus(
                        $record,
                        ServiceOperationalStatus::from((string) $data['operational_status']),
                        auth()->user(),
                        $data['note'] ?? null,
                    );

                    Notification::make()
                        ->title('Uptime status updated')
                        ->success()
                        ->send();

                    $this->refreshFormData(['operational_status', 'operational_status_updated_at']);
                }),
        ];
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    protected function contractFormSchema(Subscription $record, bool $required = false): array
    {
        $premium = $record->requiresVasLicenseExpiry();
        $must = $required;

        return [
            DatePicker::make('contract_signed_at')
                ->label('Contract signing date')
                ->native(false)
                ->required($must)
                ->helperText('Date the partner contract was signed.'),
            TextInput::make('renewal_year')
                ->label('Renewal year')
                ->numeric()
                ->minValue(1990)
                ->maxValue((int) now()->year + 20)
                ->required($must)
                ->helperText('Calendar year for this contract renewal cycle.'),
            DatePicker::make('vas_license_expires_at')
                ->label('VAS license expiry date')
                ->native(false)
                ->required($must && $premium)
                ->visible($premium)
                ->helperText($premium
                    ? 'Required for premium services.'
                    : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contractFormDefaults(Subscription $record): array
    {
        return [
            'contract_signed_at' => $record->contract_signed_at,
            'renewal_year' => $record->renewal_year,
            'vas_license_expires_at' => $record->vas_license_expires_at
                ?? $record->company?->license_valid_until,
        ];
    }
}
