<?php

namespace App\Filament\Resources\ServiceProvisionings;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Filament\Resources\ServiceProvisionings\Pages\ListServiceProvisionings;
use App\Filament\Resources\ServiceProvisionings\Pages\ViewServiceProvisioning;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\StepsRelationManager;
use App\Filament\Resources\ServiceProvisionings\Support\ProvisioningStepActions;
use App\Models\ServiceProvisioning;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceProvisioningResource extends Resource
{
    protected static ?string $model = ServiceProvisioning::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|\UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Service activation';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Service activation';

    protected static ?string $pluralModelLabel = 'Service activations';

    protected static ?string $slug = 'service-activations';

    protected static ?string $recordTitleAttribute = 'public_id';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'company',
            'service',
            'subscription.contact',
            'subscription.activatedByTicket.assignee',
            'subscription.activatedByTicket.currentApprover',
            'steps',
        ]);

        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Bypass roles (super_admin, admin, supervisor, management): unscoped view access.
        if ($user->canBypassServiceProvisioningScope()) {
            return $query;
        }

        $deptRoles = $user->provisioningDepartments();
        $isAm = $user->hasRole(User::ROLE_ACCOUNT_MANAGER);

        // No department attachment and not AM → nothing in the list.
        if ($deptRoles === [] && ! $isAm) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user, $deptRoles, $isAm): void {
            // Third-party / section users: cases for departments attached at user creation.
            if ($deptRoles !== []) {
                $q->where(function (Builder $inner) use ($deptRoles): void {
                    $inner->whereHas('steps', function (Builder $steps) use ($deptRoles): void {
                        $steps->whereIn('from_department', $deptRoles);
                    });
                });
            }

            // Account managers: only cases on subscriptions assigned to them.
            if ($isAm) {
                $method = $deptRoles !== [] ? 'orWhereHas' : 'whereHas';
                $q->{$method}('subscription.tickets', function (Builder $tickets) use ($user): void {
                    $tickets->where(function (Builder $t) use ($user): void {
                        $t->where('assigned_to_user_id', $user->id)
                            ->orWhere('current_approver_user_id', $user->id);
                    });
                });
            }
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')
                ->schema([
                TextEntry::make('public_id')
                    ->label('Case number')
                    ->weight(FontWeight::SemiBold)
                    ->copyable()
                    ->copyMessage('Case number copied'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ProvisioningStatus
                        ? $state->label()
                        : (ProvisioningStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof ProvisioningStatus
                        ? $state
                        : ProvisioningStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextEntry::make('workflow_key')
                    ->label('Workflow')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\ProvisioningWorkflow
                        ? $state->label()
                        : str_replace('_', ' ', ucfirst((string) $state))),
                TextEntry::make('current_step')
                    ->label('Current step')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->state(function (ServiceProvisioning $record): ?string {
                        $key = $record->current_step_key;
                        if (! filled($key)) {
                            return null;
                        }

                        return $record->steps->firstWhere('step_key', $key)?->label ?: (string) $key;
                    }),
                TextEntry::make('waiting_on')
                    ->label('Waiting on')
                    ->badge()
                    ->placeholder('—')
                    ->state(function (ServiceProvisioning $record): ?string {
                        $waiting = $record->steps
                            ->filter(fn ($s) => $s->status === ProvisioningStepStatus::Waiting)
                            ->map(fn ($s): string => $s->from_department instanceof ProvisioningDepartment
                                ? $s->from_department->label()
                                : (string) $s->from_department)
                            ->filter()
                            ->unique()
                            ->values();

                        return $waiting->isEmpty() ? null : $waiting->implode(', ');
                    }),
                TextEntry::make('overall_days')
                    ->label('Elapsed (OLA)')
                    ->state(fn (ServiceProvisioning $record): string => $record->overallElapsedOlaLabel()
                        .' ('.$record->overallElapsedHours().' hour(s))'),
                TextEntry::make('started_at')->label('Started')->dateTime()->placeholder('—'),
                TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
            ])->columns(2),

            Section::make('Customer')
                ->schema([
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->weight(FontWeight::SemiBold)
                    ->color('primary')
                    ->url(fn (ServiceProvisioning $record): ?string => $record->company
                        ? \App\Filament\Resources\Companies\CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
                TextEntry::make('company.legal_name')->label('Legal name')->placeholder('—')->wrap(),
                TextEntry::make('company.tin')->label('TIN number')->placeholder('—')->copyable(),
                TextEntry::make('subscription.contact.name')
                    ->label('Contact')
                    ->placeholder('—')
                    ->url(fn (ServiceProvisioning $record): ?string => $record->subscription?->contact
                        ? \App\Filament\Resources\Contacts\ContactResource::getUrl('view', ['record' => $record->subscription->contact])
                        : null),
                TextEntry::make('customer_phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => $record->company?->claimPhone()
                        ?: $record->subscription?->contact?->phone_number
                        ?: ($record->shared_payload['contact_number'] ?? null)),
                TextEntry::make('customer_email')
                    ->label('Email')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => $record->company?->email
                        ?: $record->subscription?->contact?->email
                        ?: ($record->shared_payload['contact_email'] ?? null)),
            ])->columns(2),

            Section::make('Service')
                ->collapsible()
                ->collapsed()
                ->schema([
                TextEntry::make('service.name')->label('Service')->placeholder('—'),
                TextEntry::make('service_type')
                    ->label('Service type')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => $record->service?->type
                        ?: ($record->shared_payload['service_type'] ?? null)),
                TextEntry::make('subscription.public_id')
                    ->label('Subscription')
                    ->placeholder('—')
                    ->url(fn (ServiceProvisioning $record): ?string => $record->subscription
                        ? \App\Filament\Resources\Subscriptions\SubscriptionResource::getUrl('view', ['record' => $record->subscription])
                        : null),
                TextEntry::make('subscription.status')
                    ->label('Subscription status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => \App\Enums\SubscriptionStatus::tryLabel($state) ?? (string) $state)
                    ->color(fn ($state): string => \App\Enums\SubscriptionStatus::tryColor($state)),
                TextEntry::make('subscription.contract_signed_at')
                    ->label('Contract signing date')
                    ->date()
                    ->placeholder('—'),
                TextEntry::make('subscription.operational_status')
                    ->label('Uptime status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => \App\Enums\ServiceOperationalStatus::tryLabel($state) ?? (string) $state)
                    ->color(fn ($state): string => \App\Enums\ServiceOperationalStatus::tryColor($state)),
            ])->columns(2),

            Section::make('Resource allocation')
                ->collapsible()
                ->collapsed()
                ->schema([
                TextEntry::make('detail_short_code')
                    ->label('Short code')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'short_code')),
                TextEntry::make('detail_data_sync_url')
                    ->label('Data sync URL')
                    ->placeholder('—')
                    ->wrap()
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'data_sync_url')),
            ])
                ->columns(2)
                ->visible(fn (ServiceProvisioning $record): bool => static::sectionHasPayload($record, ['short_code', 'data_sync_url'])),

            Section::make('Commercial')
                ->collapsible()
                ->collapsed()
                ->schema([
                TextEntry::make('detail_crm_order')
                    ->label('CRM order')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'crm_order')),
                TextEntry::make('detail_payer_party_type')
                    ->label('Payer party')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'payer_party_type')),
                TextEntry::make('detail_free_trial_period')
                    ->label('Free trial period')
                    ->placeholder('—')
                    ->state(function (ServiceProvisioning $record): ?string {
                        $value = static::payloadValue($record, 'free_trial_period');
                        if ($value === null || $value === '') {
                            return null;
                        }

                        return is_numeric($value)
                            ? ((int) $value).' day(s)'
                            : (string) $value;
                    }),
                TextEntry::make('detail_packages')
                    ->label('Subscription packages')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->state(function (ServiceProvisioning $record): ?string {
                        $packages = $record->shared_payload['packages'] ?? null;
                        if (! is_array($packages) || $packages === []) {
                            return null;
                        }

                        return collect($packages)
                            ->map(function ($row): string {
                                if (! is_array($row)) {
                                    return (string) $row;
                                }

                                return collect([
                                    $row['package'] ?? null,
                                    isset($row['tariff']) ? 'tariff '.$row['tariff'] : null,
                                    isset($row['price_point']) ? 'price '.$row['price_point'] : null,
                                    isset($row['sub_keyword']) ? 'sub '.$row['sub_keyword'] : null,
                                    isset($row['unsub_keyword']) ? 'unsub '.$row['unsub_keyword'] : null,
                                ])->filter()->implode(' · ');
                            })
                            ->filter()
                            ->implode("\n");
                    }),
            ])
                ->columns(2)
                ->visible(fn (ServiceProvisioning $record): bool => static::sectionHasPayload($record, [
                    'crm_order', 'payer_party_type', 'free_trial_period', 'packages',
                ])),

            Section::make('Product configuration')
                ->collapsible()
                ->collapsed()
                ->schema([
                TextEntry::make('detail_service_id')
                    ->label('Service ID')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'service_id')),
                TextEntry::make('detail_product_id')
                    ->label('Product ID')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'product_id')),
                TextEntry::make('detail_partner_id')
                    ->label('Partner ID')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'partner_id')),
                TextEntry::make('detail_spid')
                    ->label('SPID')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'spid')),
                TextEntry::make('detail_port')
                    ->label('Port')
                    ->placeholder('—')
                    ->state(fn (ServiceProvisioning $record): ?string => static::payloadValue($record, 'port')),
            ])
                ->columns(2)
                ->visible(fn (ServiceProvisioning $record): bool => static::sectionHasPayload($record, [
                    'service_id', 'product_id', 'partner_id', 'spid', 'port',
                ])),

            Section::make('Other parameters')
                ->collapsible()
                ->collapsed()
                ->schema([
                KeyValueEntry::make('other_activation_details')
                    ->label('')
                    ->columnSpanFull()
                    ->state(fn (ServiceProvisioning $record): array => static::otherActivationDetails($record)),
            ])
                ->columns(1)
                ->visible(fn (ServiceProvisioning $record): bool => static::otherActivationDetails($record) !== []),

            Section::make('Credentials')
                ->collapsed()
                ->collapsible()
                ->visible(fn (ServiceProvisioning $record): bool => (bool) ($record->shared_payload['credentials_released_to_partner'] ?? false)
                    && filled($record->shared_payload['credentials'] ?? null)
                    && (auth()->user()?->can('viewCredentials', $record) ?? false))
                ->schema([
                    ViewEntry::make('credentials')
                        ->label('')
                        ->columnSpanFull()
                        ->view('filament.infolists.secret-credentials')
                        ->state(fn (ServiceProvisioning $record): array => (array) ($record->shared_payload['credentials'] ?? [])),
                ]),
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function sectionHasPayload(ServiceProvisioning $record, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $record->shared_payload[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function payloadValue(ServiceProvisioning $record, string $key): ?string
    {
        $value = $record->shared_payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Remaining shared payload keys not shown as named Overview fields.
     *
     * @return array<string, string>
     */
    public static function otherActivationDetails(ServiceProvisioning $record): array
    {
        $known = [
            'credentials',
            'credentials_released_to_partner',
            'credentials_released_at',
            'smsc_password',
            'smsc_username',
            'company_name',
            'customer_name',
            'contact_number',
            'contact_email',
            'service_type',
            'service_name',
            'short_code',
            'data_sync_url',
            'crm_order',
            'tariff',
            'service_price',
            'payer_party_type',
            'free_trial_period',
            'service_id',
            'product_id',
            'partner_id',
            'spid',
            'port',
            'packages',
        ];

        return collect($record->shared_payload ?? [])
            ->except($known)
            ->mapWithKeys(function ($value, $key): array {
                $label = str_replace('_', ' ', ucfirst((string) $key));
                if (is_scalar($value) || $value === null) {
                    $formatted = filled($value) || $value === 0 || $value === '0'
                        ? (string) $value
                        : null;
                } else {
                    $formatted = json_encode($value, JSON_UNESCAPED_UNICODE);
                    if (in_array($formatted, ['[]', '{}', 'null', ''], true)) {
                        $formatted = null;
                    }
                }

                return $formatted === null ? [] : [$label => $formatted];
            })
            ->filter()
            ->sortKeys()
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('public_id')->label('PRV')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable()->wrap(),
                TextColumn::make('service.name')->label('Service')->wrap(),
                TextColumn::make('am_handler')
                    ->label('AM handler')
                    ->state(fn (ServiceProvisioning $record): string => $record->accountHandler()?->name ?: '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->whereHas('subscription.activatedByTicket.assignee', function (Builder $users) use ($search): void {
                                $users->where('name', 'ilike', "%{$search}%");
                            })->orWhereHas('subscription.tickets.assignee', function (Builder $users) use ($search): void {
                                $users->where('name', 'ilike', "%{$search}%");
                            });
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('subscriptions as am_subs', 'am_subs.id', '=', 'service_provisionings.subscription_id')
                            ->leftJoin('tickets as am_tickets', 'am_tickets.id', '=', 'am_subs.activated_by_ticket_id')
                            ->leftJoin('users as am_users', 'am_users.id', '=', 'am_tickets.assigned_to_user_id')
                            ->orderBy('am_users.name', $direction)
                            ->select('service_provisionings.*');
                    })
                    ->toggleable()
                    ->wrap(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('waiting_on')
                    ->label('Waiting on')
                    ->state(function (ServiceProvisioning $record): string {
                        $labels = $record->steps
                            ->filter(fn ($s) => $s->status === ProvisioningStepStatus::Waiting)
                            ->map(function ($s): string {
                                $dept = $s->from_department instanceof ProvisioningDepartment
                                    ? $s->from_department->label()
                                    : (string) $s->from_department;

                                return $dept !== '' ? $dept : '—';
                            })
                            ->unique()
                            ->values();

                        return $labels->isEmpty() ? '—' : $labels->implode(', ');
                    })
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('current_step_key')->label('Current step')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('overall_days')
                    ->label('OLA')
                    ->state(fn (ServiceProvisioning $record): string => $record->overallElapsedOlaLabel())
                    ->description(fn (ServiceProvisioning $record): string => $record->overallElapsedHours().' hour(s)')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw('COALESCE(completed_at, NOW()) - COALESCE(started_at, created_at) '.$direction);
                    }),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ProvisioningStatus::cases())->mapWithKeys(
                    fn (ProvisioningStatus $s) => [$s->value => $s->label()]
                )),
                SelectFilter::make('waiting_department')
                    ->label('Waiting section')
                    ->options(
                        collect(ProvisioningDepartment::cases())
                            ->reject(fn (ProvisioningDepartment $d) => $d === ProvisioningDepartment::Partner)
                            ->mapWithKeys(fn (ProvisioningDepartment $d) => [$d->value => $d->label()])
                            ->all()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas('steps', function (Builder $steps) use ($value): void {
                            $steps->where('status', ProvisioningStepStatus::Waiting->value)
                                ->where('from_department', $value);
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->slideOver(),
                ActionGroup::make(ProvisioningStepActions::forTable())
                    ->label(fn (ServiceProvisioning $record): string => ProvisioningStepActions::groupLabel($record))
                    ->icon(false)
                    ->color('primary')
                    ->button()
                    ->visible(fn (ServiceProvisioning $record): bool => ProvisioningStepActions::actionableSteps($record)->isNotEmpty()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            StepsRelationManager::class,
            MessagesRelationManager::class,
            AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceProvisionings::route('/'),
            'view' => ViewServiceProvisioning::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
