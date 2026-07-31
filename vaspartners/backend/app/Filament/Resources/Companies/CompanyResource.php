<?php

namespace App\Filament\Resources\Companies;

use App\Enums\CompanyRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Companies\RelationManagers\ServiceRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Companies\RelationManagers\SubscriptionsRelationManager;
use App\Models\Company;
use App\Models\Service;
use App\Services\CompanyPurgeService;
use App\Services\SmsService;
use App\Support\PhoneNumber;
use App\Support\TinNumber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Companies';

    protected static ?string $modelLabel = 'Company';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'tin', 'phone', 'email'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        /** @var Company $record */
        return array_filter([
            'TIN number' => $record->tin,
            'Phone' => $record->phone,
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Company::query()->awaitingTinApproval()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->disabled(fn (?Company $record): bool => (bool) $record?->isErcaIdentityLocked())
                ->dehydrated(fn (?Company $record): bool => ! (bool) $record?->isErcaIdentityLocked())
                ->helperText(fn (?Company $record): ?string => $record?->isErcaIdentityLocked()
                    ? 'Locked after ERCA match.'
                    : null),
            TextInput::make('legal_name')
                ->label('ERCA legal name')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('tin')
                ->label('TIN number')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(32)
                ->disabled(fn (?Company $record): bool => (bool) $record?->isErcaIdentityLocked())
                ->dehydrated(fn (?Company $record): bool => ! (bool) $record?->isErcaIdentityLocked())
                ->dehydrateStateUsing(fn (?string $state): string => TinNumber::normalize((string) $state))
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! TinNumber::isValid($value)) {
                        $fail(TinNumber::message());
                    }
                })
                ->helperText(fn (?Company $record): ?string => $record?->isErcaIdentityLocked()
                    ? 'Locked after ERCA match.'
                    : 'Exactly 10 digits. Unique — never duplicated.'),
            TextInput::make('phone')
                ->tel()
                ->maxLength(32)
                ->helperText('Last 9 digits.')
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\PhoneNumber::normalizeNullable($state)),
            TextInput::make('email')
                ->email()
                ->maxLength(255)
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\EmailAddress::normalize($state)),
            Textarea::make('address')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Active'),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->schema([
                    TextEntry::make('name')->label('Company name'),
                    TextEntry::make('tin')->label('TIN number'),
                    TextEntry::make('tin_ok')
                        ->label('TIN number status')
                        ->badge()
                        ->state(fn (Company $record): bool => $record->isTinValidated())
                        ->formatStateUsing(fn ($state) => $state ? 'Verified' : 'Not verified')
                        ->color(fn ($state) => $state ? 'success' : 'warning'),
                    TextEntry::make('is_active')
                        ->label('Active')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                        ->color(fn ($state) => $state ? 'success' : 'danger'),
                    TextEntry::make('owner_name')
                        ->label('Owner')
                        ->state(fn (Company $record): ?string => $record->ownerContact()?->name)
                        ->placeholder('No owner')
                        ->color(fn (Company $record): string => $record->isOwnerless() ? 'warning' : 'success'),
                    TextEntry::make('members_count')
                        ->label('Members')
                        ->state(fn (Company $record): int => $record->memberCount()),
                ])->columns(3),
            Section::make('Contact')
                ->schema([
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('address')->columnSpanFull()->placeholder('—'),
                ])->columns(2),
            Section::make('ERCA')
                ->schema([
                    TextEntry::make('legal_name')
                        ->label('Legal name')
                        ->placeholder('—'),
                    TextEntry::make('erca_name_status')
                        ->label('Name match')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\ErcaNameStatus
                            ? $state->label()
                            : (\App\Enums\ErcaNameStatus::tryFrom((string) $state)?->label() ?? 'Not checked'))
                        ->color(fn ($state) => match ($state instanceof \App\Enums\ErcaNameStatus ? $state->value : (string) $state) {
                            'matched', 'accepted_legal', 'kept_both', 'partner_entered' => 'success',
                            'mismatch_pending', 'name_missing' => 'warning',
                            'not_found', 'failed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('erca_verified_at')
                        ->label('Verified at')
                        ->dateTime()
                        ->placeholder('—'),
                ])->columns(2),
            Section::make('Record')
                ->collapsed()
                ->schema([
                    TextEntry::make('public_id')->label('ID'),
                    TextEntry::make('legacy_mvas_id')
                        ->label('Legacy MVAS ID')
                        ->placeholder('—')
                        ->visible(fn (Company $record): bool => filled($record->legacy_mvas_id)),
                    TextEntry::make('erca_last_checked_at')
                        ->label('ERCA last checked')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withExists([
                    'ownerMembership as has_owner_flag',
                ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('legal_name')
                    ->label('Legal name')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('ownership_flag')
                    ->label('Ownership')
                    ->badge()
                    ->state(fn (Company $record): string => $record->isOwnerless() ? 'No owner' : 'Has owner')
                    ->color(fn (Company $record): string => $record->isOwnerless() ? 'warning' : 'success')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderByRaw(
                            '(EXISTS (SELECT 1 FROM company_memberships WHERE company_memberships.company_id = companies.id AND company_memberships.role = ?)) '.$direction,
                            ['owner'],
                        );
                    }),
                TextColumn::make('tin')
                    ->label('TIN number')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Company $record): string => static::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->weight(FontWeight::SemiBold)
                    ->tooltip('View company details'),
                IconColumn::make('tin_ok')
                    ->label('TIN number verified')
                    ->boolean()
                    ->state(fn (Company $record): bool => $record->isTinValidated())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            'CASE WHEN erca_tin_verified = true THEN 1 ELSE 0 END '.$direction
                        );
                    }),
                TextColumn::make('erca_name_status')
                    ->label('ERCA name')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\ErcaNameStatus
                        ? $state->value
                        : (string) ($state ?: 'unchecked'))
                    ->color(fn ($state) => match ($state instanceof \App\Enums\ErcaNameStatus ? $state->value : (string) $state) {
                        'matched', 'accepted_legal', 'kept_both', 'partner_entered' => 'success',
                        'mismatch_pending', 'name_missing' => 'warning',
                        'not_found', 'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('erca_verified_at')
                    ->label('ERCA verified at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable()
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = preg_replace('/\D+/', '', $search) ?? '';
                        if ($digits === '') {
                            return $query->where('phone', 'ilike', '%'.$search.'%');
                        }

                        $normalized = PhoneNumber::normalizeNullable($digits) ?? $digits;
                        $tail = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

                        return $query->where(function (Builder $q) use ($search, $digits, $normalized, $tail): void {
                            $q->where('phone', 'ilike', '%'.$search.'%')
                                ->orWhere('phone', 'ilike', '%'.$digits.'%')
                                ->orWhere('phone', 'ilike', '%'.$normalized.'%')
                                ->orWhere('phone', 'ilike', '%'.$tail.'%');
                        });
                    }),
                TextColumn::make('email')->label('Email')->toggleable()->placeholder('—')->searchable(),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->state(fn (Company $record): ?string => $record->ownerContact()?->name)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('members_count')
                    ->counts('memberships')
                    ->label('Members')
                    ->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Company $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('tin_verification')
                    ->label('TIN number verification')
                    ->options([
                        'validated' => 'TIN number verified',
                        'awaiting' => 'Valid TIN number — awaiting verification',
                        'mismatch' => 'Name mismatch (consent needed)',
                        'invalid' => 'Invalid / missing TIN number',
                        'erca_matched' => 'Name matched',
                        'erca_not_found' => 'TIN number not found',
                        'erca_name_missing' => 'TIN number found — name missing',
                        'erca_failed' => 'Verification failed',
                        'erca_unchecked' => 'Not checked yet',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'validated' => $query->tinApproved(),
                            'awaiting' => $query->awaitingTinApproval(),
                            'mismatch' => $query->ercaNameMismatchPending(),
                            'invalid' => $query->invalidOrMissingTin(),
                            'erca_matched' => $query->where('erca_name_status', \App\Enums\ErcaNameStatus::Matched->value),
                            'erca_not_found' => $query->where('erca_name_status', \App\Enums\ErcaNameStatus::NotFound->value),
                            'erca_name_missing' => $query->where('erca_name_status', \App\Enums\ErcaNameStatus::NameMissing->value),
                            'erca_failed' => $query->where('erca_name_status', \App\Enums\ErcaNameStatus::Failed->value),
                            'erca_unchecked' => $query->where(function (Builder $q): void {
                                $q->whereNull('erca_name_status')
                                    ->orWhere('erca_name_status', '')
                                    ->orWhere('erca_name_status', \App\Enums\ErcaNameStatus::Unchecked->value);
                            }),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('no_owner')
                    ->label('Ownership')
                    ->placeholder('All ownership')
                    ->trueLabel('No owner only')
                    ->falseLabel('Has owner only')
                    ->queries(
                        true: fn ($query) => $query->ownerless(),
                        false: fn ($query) => $query->whereHas(
                            'memberships',
                            fn ($q) => $q->where('role', CompanyRole::Owner->value),
                        ),
                        blank: fn ($query) => $query,
                    ),
                SelectFilter::make('service_id')
                    ->label('Services')
                    ->options(fn (): array => Service::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->multiple()
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $serviceIds = collect(Arr::wrap($data['values'] ?? []))
                            ->filter(fn ($id) => filled($id))
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();

                        if ($serviceIds === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'subscriptions',
                            fn (Builder $subscriptions) => $subscriptions->whereIn('service_id', $serviceIds),
                        );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('send_sms')
                    ->label('Send SMS')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('primary')
                    ->visible(fn (Company $record): bool => (bool) auth()->user()?->canSendCompanySms()
                        && filled($record->phone))
                    ->form([
                        Textarea::make('message')
                            ->label('SMS message')
                            ->required()
                            ->rows(5)
                            ->maxLength(640)
                            ->helperText('Event / ad-hoc SMS to this company phone. Max 640 characters.'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Company $record): string => 'Send SMS to '.$record->name)
                    ->action(function (Company $record, array $data, SmsService $sms): void {
                        static::dispatchCompanySms($record, (string) $data['message'], $sms);
                    }),
                static::deleteCompanyAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('send_sms')
                        ->label('Send SMS to selected')
                        ->icon('heroicon-o-chat-bubble-left-ellipsis')
                        ->color('primary')
                        ->visible(fn (): bool => (bool) auth()->user()?->canBulkSendCompanySms())
                        ->form([
                            Textarea::make('message')
                                ->label('SMS message')
                                ->required()
                                ->rows(5)
                                ->maxLength(640)
                                ->helperText('Event / ad-hoc SMS to each selected company. Max 640 characters.'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Send SMS to selected companies')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, SmsService $sms): void {
                            $result = static::queueSmsToCompanies(
                                $records,
                                (string) ($data['message'] ?? ''),
                                $sms,
                            );

                            Notification::make()
                                ->title($result['queued'] > 0
                                    ? "Queued SMS for {$result['queued']} company(ies)"
                                    : 'No SMS queued')
                                ->body($result['skipped'] > 0
                                    ? "{$result['skipped']} skipped (missing or invalid phone)."
                                    : null)
                                ->color($result['queued'] > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * @param  Builder<Company>|Collection<int, Company>|iterable<Company>  $companies
     * @return array{queued:int, skipped:int}
     */
    public static function queueSmsToCompanies(Builder|iterable $companies, string $message, SmsService $sms): array
    {
        $message = trim($message);
        $queued = 0;
        $skipped = 0;

        if ($message === '') {
            return ['queued' => 0, 'skipped' => 0];
        }

        $iterator = $companies instanceof Builder
            ? $companies->cursor()
            : $companies;

        foreach ($iterator as $company) {
            if (! $company instanceof Company) {
                continue;
            }

            if (! auth()->user()?->canSendCompanySms() && ! auth()->user()?->canBulkSendCompanySms()) {
                $skipped++;

                continue;
            }

            if (! filled($company->phone) || ! $sms->ensurePhoneIsLocal($company->phone)) {
                $skipped++;

                continue;
            }

            $sms->send($company->phone, $message);
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public static function dispatchCompanySms(Company $company, string $message, SmsService $sms): void
    {
        $result = static::queueSmsToCompanies([$company], $message, $sms);

        if ($result['queued'] < 1) {
            Notification::make()
                ->title('Cannot send SMS')
                ->body('Company has no usable local mobile number on file.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('SMS queued')
            ->body('Message queued for '.$company->phone)
            ->success()
            ->send();
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            StatusHistoryRelationManager::class,
            ServiceRequestsRelationManager::class,
            SubscriptionsRelationManager::class,
            ChangeRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // Companies are created by partners (create/attach flow).
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'hasRole') || ! $user->hasRole('super_admin')) {
            return false;
        }

        return $record instanceof Company
            && app(CompanyPurgeService::class)->canForcePurge($record);
    }

    public static function canForceDelete($record): bool
    {
        return static::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user && method_exists($user, 'hasRole') && $user->hasRole('super_admin'));
    }

    public static function canForceDeleteAny(): bool
    {
        return static::canDeleteAny();
    }

    /**
     * Super-admin only permanent delete via CompanyPurgeService.
     *
     * @return array{ok: bool, stats?: array<string, mixed>}
     */
    public static function purgeCompanyRecord(Company $record, CompanyPurgeService $purge): array
    {
        try {
            $stats = $purge->forcePurge($record);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot delete company')
                ->body(collect($e->errors())->flatten()->first() ?: $e->getMessage())
                ->danger()
                ->send();

            return ['ok' => false];
        }

        Notification::make()
            ->title('Company deleted')
            ->body(sprintf(
                '%s removed (memberships %d, tickets %d, subscriptions %d, contacts %d).',
                $stats['company'],
                $stats['memberships'],
                $stats['tickets'],
                $stats['subscriptions'],
                $stats['contacts'],
            ))
            ->success()
            ->send();

        return ['ok' => true, 'stats' => $stats];
    }

    public static function deleteCompanyAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Company $record): bool => static::canDelete($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Company $record): string => 'Delete company '.$record->name)
            ->modalDescription(
                'Permanently deletes this company and related memberships, tickets, subscriptions, and orphan contacts. Companies with an owner, verified TIN number, and at least one subscription cannot be deleted.'
            )
            ->modalSubmitActionLabel('Delete permanently')
            ->action(function (Company $record, CompanyPurgeService $purge): void {
                static::purgeCompanyRecord($record, $purge);
            });
    }
}
