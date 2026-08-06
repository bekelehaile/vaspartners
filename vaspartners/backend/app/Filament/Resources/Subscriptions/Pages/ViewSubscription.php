<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\ServiceOperationalStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionProvisioningLogService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
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
                ->icon(Heroicon::CalendarDays)
                ->color('primary')
                ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Closed)
                ->modalHeading('Record contract details')
                ->modalDescription(fn (Subscription $record): string => $this->contractModalDescription($record))
                ->modalIcon(Heroicon::CalendarDays)
                ->modalIconColor('primary')
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel('Save details')
                ->modalCancelActionLabel('Cancel')
                ->form(fn (Subscription $record): array => $this->contractFormSchema($record, required: true))
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
                        'renewal_years',
                        'renewal_date',
                        'automatic_renewal',
                        'vas_license_expires_at',
                    ]);
                }),
            Action::make('close_contract')
                ->label('Close subscription')
                ->icon(Heroicon::LockClosed)
                ->color('danger')
                ->visible(fn (Subscription $record): bool => $record->status->isAlive()
                    || $record->status === SubscriptionStatus::Expired)
                ->modalHeading('Close subscription')
                ->modalDescription(fn (Subscription $record): string => $this->contractModalDescription($record, closing: true))
                ->modalIcon(Heroicon::LockClosed)
                ->modalIconColor('danger')
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel('Close subscription')
                ->modalCancelActionLabel('Cancel')
                ->form(fn (Subscription $record): array => [
                    ...$this->contractFormSchema($record, required: true),
                    Section::make('Closing note')
                        ->icon(Heroicon::ChatBubbleBottomCenterText)
                        ->compact()
                        ->schema([
                            Textarea::make('note')
                                ->label('Note')
                                ->rows(3)
                                ->placeholder('Optional note…')
                                ->columnSpanFull(),
                        ]),
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
                        'renewal_years',
                        'renewal_date',
                        'automatic_renewal',
                        'vas_license_expires_at',
                        'next_renewal_due_at',
                    ]);
                }),
            Action::make('set_uptime')
                ->label('Set uptime status')
                ->icon(Heroicon::Signal)
                ->color('gray')
                ->modalHeading('Set uptime status')
                ->modalDescription(fn (Subscription $record): string => $this->uptimeModalDescription($record))
                ->modalIcon(Heroicon::Signal)
                ->modalIconColor('gray')
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel('Update status')
                ->modalCancelActionLabel('Cancel')
                ->fillForm(fn (Subscription $record): array => [
                    'operational_status' => ($record->operational_status instanceof ServiceOperationalStatus
                        ? $record->operational_status
                        : ServiceOperationalStatus::tryFrom((string) ($record->operational_status ?? ''))
                    )?->value ?? ServiceOperationalStatus::Unknown->value,
                    'note' => null,
                ])
                ->form(fn (Subscription $record): array => $this->uptimeFormSchema($record))
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

    protected function uptimeModalDescription(Subscription $record): string
    {
        $service = $record->service?->name ?: 'Service';
        $company = $record->company?->name ?: 'Company';

        return "{$service} · {$company}";
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    protected function uptimeFormSchema(Subscription $record): array
    {
        $current = $record->operational_status instanceof ServiceOperationalStatus
            ? $record->operational_status
            : ServiceOperationalStatus::tryFrom((string) ($record->operational_status ?? ''))
                ?? ServiceOperationalStatus::Unknown;

        return [
            Placeholder::make('uptime_context')
                ->label('')
                ->content(fn (): HtmlString => new HtmlString($this->uptimeContextHtml($record, $current))),
            Section::make('Status')
                ->icon(Heroicon::Signal)
                ->compact()
                ->schema([
                    Select::make('operational_status')
                        ->label('Uptime status')
                        ->options(collect(ServiceOperationalStatus::cases())
                            ->mapWithKeys(fn (ServiceOperationalStatus $s) => [$s->value => $s->label()])
                            ->all())
                        ->native(false)
                        ->required()
                        ->prefixIcon(Heroicon::Signal)
                        ->prefixIconColor('gray')
                        ->validationMessages([
                            'required' => 'Uptime status is required.',
                        ])
                        ->markAsRequired()
                        ->columnSpanFull(),
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->placeholder('Optional note…')
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function uptimeContextHtml(Subscription $record, ServiceOperationalStatus $current): string
    {
        $service = e($record->service?->name ?: 'Service');
        $company = e($record->company?->name ?: 'Company');
        $statusLabel = e($current->label());
        $updated = $record->operational_status_updated_at
            ? e($record->operational_status_updated_at->format('d M Y H:i'))
            : null;

        $badgeStyle = match ($current) {
            ServiceOperationalStatus::Up => 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;',
            ServiceOperationalStatus::Degraded => 'background:#fffbeb;color:#b45309;border:1px solid #fde68a;',
            ServiceOperationalStatus::Down => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
            ServiceOperationalStatus::Unknown => 'background:#f8fafc;color:#475569;border:1px solid #e2e8f0;',
        };

        $meta = $updated
            ? "<span style=\"font-size:0.75rem;color:#64748b;\">Updated {$updated}</span>"
            : '';

        return <<<HTML
<div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;padding:0.85rem 1rem;border-radius:0.75rem;background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%);border:1px solid #e2e8f0;">
  <span style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8125rem;font-weight:600;color:#0f172a;">{$company}</span>
  <span style="color:#94a3b8;">·</span>
  <span style="font-size:0.8125rem;color:#334155;">{$service}</span>
  <span style="margin-left:auto;display:inline-flex;align-items:center;gap:0.5rem;">
    {$meta}
    <span style="display:inline-flex;align-items:center;padding:0.2rem 0.55rem;border-radius:999px;font-size:0.6875rem;font-weight:700;letter-spacing:0.02em;text-transform:uppercase;{$badgeStyle}">{$statusLabel}</span>
  </span>
</div>
HTML;
    }

    protected function contractModalDescription(Subscription $record, bool $closing = false): string
    {
        $service = $record->service?->name ?: 'Service';
        $company = $record->company?->name ?: 'Company';

        return "{$service} · {$company}";
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    protected function contractFormSchema(Subscription $record, bool $required = true): array
    {
        $premium = $record->requiresVasLicenseExpiry();
        $must = $required;

        $syncRenewal = function (callable $set, callable $get): void {
            $composed = Subscription::composeRenewalDate(
                $get('contract_signed_at'),
                $get('renewal_years'),
            );
            $set('renewal_date', $composed?->toDateString());
        };

        return [
            Placeholder::make('contract_context')
                ->label('')
                ->content(fn (): HtmlString => new HtmlString($this->contractContextHtml($record))),
            Section::make('Contract dates')
                ->icon(Heroicon::CalendarDays)
                ->compact()
                ->columns(2)
                ->schema([
                    Checkbox::make('automatic_renewal')
                        ->label('Automatic renewal')
                        ->dehydrated()
                        ->columnSpanFull(),
                    DatePicker::make('contract_signed_at')
                        ->label('Contract signing date')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->closeOnDateSelection()
                        ->maxDate(now())
                        ->suffixIcon(Heroicon::Calendar, isInline: true)
                        ->suffixIconColor('gray')
                        ->required($must)
                        ->live()
                        ->afterStateUpdated(fn ($state, callable $set, callable $get) => $syncRenewal($set, $get))
                        ->rules([
                            $must ? 'required' : 'nullable',
                            'date',
                            'before_or_equal:today',
                        ])
                        ->validationMessages([
                            'required' => 'Contract signing date is required.',
                            'before_or_equal' => 'Contract signing date cannot be in the future.',
                        ])
                        ->markAsRequired($must)
                        ->columnSpan(1),
                    TextInput::make('renewal_years')
                        ->label('Renewal year')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->step(1)
                        ->required($must)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, callable $get) => $syncRenewal($set, $get))
                        ->rules([
                            $must ? 'required' : 'nullable',
                            'integer',
                            'min:1',
                            'max:10',
                        ])
                        ->validationMessages([
                            'required' => 'Renewal year is required.',
                            'min' => 'Renewal year must be at least 1.',
                            'max' => 'Renewal year must be 10 or less.',
                        ])
                        ->markAsRequired($must)
                        ->columnSpan(1),
                    DatePicker::make('renewal_date')
                        ->label('Renewal date')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->suffixIcon(Heroicon::Calendar, isInline: true)
                        ->suffixIconColor('gray')
                        ->disabled()
                        ->dehydrated()
                        ->required($must)
                        ->rules([
                            $must ? 'required' : 'nullable',
                            'date',
                        ])
                        ->validationMessages([
                            'required' => 'Renewal date is required.',
                        ])
                        ->markAsRequired($must)
                        ->columnSpanFull(),
                    DatePicker::make('vas_license_expires_at')
                        ->label('VAS license expiry date')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->closeOnDateSelection()
                        ->suffixIcon(Heroicon::Calendar, isInline: true)
                        ->suffixIconColor('gray')
                        ->required($must && $premium)
                        ->visible($premium)
                        ->rules(fn (): array => ($must && $premium)
                            ? ['required', 'date']
                            : ['nullable', 'date'])
                        ->validationMessages([
                            'required' => 'VAS license expiry date is required.',
                        ])
                        ->markAsRequired($must && $premium)
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function contractContextHtml(Subscription $record): string
    {
        $service = e($record->service?->name ?: 'Service');
        $company = e($record->company?->name ?: 'Company');
        $premium = $record->requiresVasLicenseExpiry();
        $badgeLabel = $premium ? 'Premium' : 'Non-premium';
        $badgeClass = $premium
            ? 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;'
            : 'background:#f8fafc;color:#334155;border:1px solid #e2e8f0;';

        return <<<HTML
<div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;padding:0.85rem 1rem;border-radius:0.75rem;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);border:1px solid #e2e8f0;">
  <span style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8125rem;font-weight:600;color:#0f172a;">{$company}</span>
  <span style="color:#94a3b8;">·</span>
  <span style="font-size:0.8125rem;color:#334155;">{$service}</span>
  <span style="margin-left:auto;display:inline-flex;align-items:center;padding:0.2rem 0.55rem;border-radius:999px;font-size:0.6875rem;font-weight:700;letter-spacing:0.02em;text-transform:uppercase;{$badgeClass}">{$badgeLabel}</span>
</div>
HTML;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contractFormDefaults(Subscription $record): array
    {
        $years = $record->renewal_years
            ?? Subscription::renewalYearsBetween($record->contract_signed_at, $record->renewal_date);

        return [
            'automatic_renewal' => (bool) $record->automatic_renewal,
            'contract_signed_at' => $record->contract_signed_at,
            'renewal_years' => $years,
            'renewal_date' => $record->renewal_date
                ?? Subscription::composeRenewalDate($record->contract_signed_at, $years),
            'vas_license_expires_at' => $record->vas_license_expires_at
                ?? $record->company?->license_valid_until,
        ];
    }
}
