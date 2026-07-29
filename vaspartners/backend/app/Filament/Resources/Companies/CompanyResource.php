<?php

namespace App\Filament\Resources\Companies;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Companies\RelationManagers\ServiceRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\SubscriptionsRelationManager;
use App\Models\Company;
use App\Models\Service;
use App\Services\CompanyMembershipService;
use App\Services\CompanyPurgeService;
use App\Services\SmsService;
use App\Support\TinNumber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Companies';

    protected static ?string $modelLabel = 'Company';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Company::query()
            ->where('approval_status', CompanyApprovalStatus::Pending)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('tin')
                ->label('TIN')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(32)
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! TinNumber::isValid($value)) {
                        $fail(TinNumber::message());
                    }
                })
                ->helperText('Ethiopian TIN: exactly 10 digits. Unique. Partners cannot use services until Active is on and TIN is validated.'),
            TextInput::make('phone')
                ->tel()
                ->maxLength(32)
                ->helperText('Saved as last 9 digits. Partners may share the same phone across multiple companies.')
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\PhoneNumber::normalizeNullable($state)),
            TextInput::make('email')
                ->email()
                ->maxLength(255)
                ->helperText('Partners may share the same email across multiple companies.')
                ->dehydrateStateUsing(fn (?string $state): ?string => \App\Support\EmailAddress::normalize($state)),
            Textarea::make('address')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Normally set by Approve profile. When off, company contacts cannot sign in to the portal and cannot use VAS services.'),
            Toggle::make('tin_validated')
                ->label('TIN validated')
                ->helperText('Set via Validate TIN action after verifying the Ethiopian TIN. Required before partners can submit service requests.'),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')->schema([
                TextEntry::make('public_id')->label('ID'),
                TextEntry::make('name'),
                TextEntry::make('tin')->label('TIN'),
                TextEntry::make('tin_validated')
                    ->label('TIN validated')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Validated' : 'Not validated')
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CompanyApprovalStatus
                        ? $state->label()
                        : (CompanyApprovalStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state) => match ($state instanceof CompanyApprovalStatus ? $state->value : $state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextEntry::make('is_active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextEntry::make('ownership_flag')
                    ->label('Ownership')
                    ->badge()
                    ->state(fn (Company $record): string => $record->isOwnerless() ? 'No owner' : 'Has owner')
                    ->color(fn (Company $record): string => $record->isOwnerless() ? 'warning' : 'success')
                    ->helperText(fn (Company $record): ?string => $record->isOwnerless()
                        ? 'No membership/owner yet — Fayda phone claim or Assign owner after verification.'
                        : null),
                TextEntry::make('owner_name')
                    ->label('Owner')
                    ->state(fn (Company $record): ?string => $record->ownerContact()?->name)
                    ->placeholder('—')
                    ->color('success'),
                TextEntry::make('legacy_mvas_id')
                    ->label('Legacy MVAS ID')
                    ->placeholder('—')
                    ->visible(fn (Company $record): bool => filled($record->legacy_mvas_id)),
                TextEntry::make('members_count')
                    ->label('Total people')
                    ->state(fn (Company $record): int => $record->memberCount()),
                TextEntry::make('approvedBy.name')->label('Approved / reviewed by')->placeholder('—'),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('approval_note')->label('Approval note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('address')->columnSpanFull()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('creator.name')->label('Created by partner')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withExists([
                'ownerMembership as has_owner_flag',
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
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
                TextColumn::make('tin')->label('TIN')->searchable()->sortable(),
                IconColumn::make('tin_validated')->boolean()->label('TIN OK'),
                TextColumn::make('phone')->label('Phone')->toggleable()->placeholder('—'),
                TextColumn::make('email')->label('Email')->toggleable()->placeholder('—'),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->state(fn (Company $record): ?string => $record->ownerContact()?->name)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CompanyApprovalStatus
                        ? $state->label()
                        : (CompanyApprovalStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state) => match ($state instanceof CompanyApprovalStatus ? $state->value : $state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('members_count')
                    ->counts('memberships')
                    ->label('Members')
                    ->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('approval_status')->options([
                    CompanyApprovalStatus::Pending->value => CompanyApprovalStatus::Pending->label(),
                    CompanyApprovalStatus::Approved->value => CompanyApprovalStatus::Approved->label(),
                    CompanyApprovalStatus::Rejected->value => CompanyApprovalStatus::Rejected->label(),
                ]),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('tin_validated')->label('TIN validated'),
                TernaryFilter::make('no_owner')
                    ->label('No owner')
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
                EditAction::make(),
                Action::make('force_purge')
                    ->label('Delete permanently')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Company $record): bool => ! $record->tin_validated)
                    ->requiresConfirmation()
                    ->modalHeading('Permanently delete company?')
                    ->modalDescription('This permanently deletes the company, its memberships, subscriptions, service requests, attachments, and contacts that belong only to this company. Cannot be undone. Companies with a validated TIN cannot be deleted.')
                    ->action(function (Company $record, CompanyPurgeService $purge): void {
                        try {
                            $stats = $purge->forcePurge($record);
                            Notification::make()
                                ->title('Company permanently deleted')
                                ->body(sprintf(
                                    'Removed %d contact(s), %d subscription(s), %d ticket(s), %d document(s).',
                                    $stats['contacts'],
                                    $stats['subscriptions'],
                                    $stats['tickets'],
                                    $stats['documents'],
                                ))
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('Could not delete company')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
                Action::make('validate_tin')
                    ->label('Validate TIN')
                    ->icon('heroicon-o-identification')
                    ->color('success')
                    ->visible(fn (Company $record): bool => filled($record->tin) && ! $record->tin_validated)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Company $record): string => 'Validate TIN '.$record->tin.'?')
                    ->modalDescription('Confirm this Ethiopian TIN was verified. Partners can submit service requests only after TIN validation.')
                    ->action(function (Company $record, CompanyMembershipService $membership): void {
                        try {
                            $membership->markTinValidated($record);
                            Notification::make()->title('TIN validated')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Could not validate TIN')
                                ->body(collect($e->errors())->flatten()->first() ?: $e->getMessage())
                                ->danger()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not validate TIN')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Company $record): bool => ! $record->isApproved())
                    ->form([
                        Textarea::make('approval_note')->label('Note to partner (optional)'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Approve company profile')
                    ->modalDescription('Confirm all required company information is complete. The creating partner remains the owner.')
                    ->action(function (Company $record, array $data, CompanyMembershipService $membership): void {
                        try {
                            $membership->approveCompany($record, auth()->user(), $data['approval_note'] ?? null);
                            Notification::make()->title('Company approved')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Company $record): bool => ! $record->isApproved()
                        && ($record->approval_status !== CompanyApprovalStatus::Rejected))
                    ->form([
                        Textarea::make('approval_note')->label('What is missing / needs correction')->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Company $record, array $data, CompanyMembershipService $membership): void {
                        try {
                            $membership->rejectCompany($record, auth()->user(), $data['approval_note'] ?? null);
                            Notification::make()->title('Company rejected')->warning()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('validate_tin')
                        ->label('Validate TIN')
                        ->icon('heroicon-o-identification')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Validate TIN for selected companies?')
                        ->modalDescription('Marks each selected company TIN as validated when it is a valid 10-digit Ethiopian TIN.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, CompanyMembershipService $membership): void {
                            $validated = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $company) {
                                if (! $company instanceof Company) {
                                    continue;
                                }

                                if ($company->tin_validated) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $membership->markTinValidated($company);
                                    $validated++;
                                } catch (Throwable $e) {
                                    $failed++;
                                }
                            }

                            Notification::make()
                                ->title($validated > 0
                                    ? "Validated TIN for {$validated} company(ies)"
                                    : 'No TINs validated')
                                ->body(trim(implode(' ', array_filter([
                                    $skipped > 0 ? "{$skipped} already validated." : null,
                                    $failed > 0 ? "{$failed} skipped (invalid or missing TIN)." : null,
                                ]))) ?: null)
                                ->color($validated > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('force_purge_selected')
                        ->label('Delete permanently')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Permanently delete selected companies?')
                        ->modalDescription('Permanently deletes each company plus memberships, subscriptions, tickets, attachments, and exclusive contacts. Companies with a validated TIN are skipped and cannot be deleted.')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => static::canDeleteAny())
                        ->action(function (Collection $records, CompanyPurgeService $purge): void {
                            $deleted = 0;
                            $failed = 0;
                            $skippedTin = 0;
                            $contacts = 0;
                            $docs = 0;

                            foreach ($records as $company) {
                                if (! $company instanceof Company) {
                                    continue;
                                }

                                if ($company->tin_validated) {
                                    $skippedTin++;

                                    continue;
                                }

                                try {
                                    $stats = $purge->forcePurge($company);
                                    $deleted++;
                                    $contacts += $stats['contacts'];
                                    $docs += $stats['documents'];
                                } catch (Throwable $e) {
                                    report($e);
                                    $failed++;
                                }
                            }

                            Notification::make()
                                ->title($deleted > 0
                                    ? "Permanently deleted {$deleted} company(ies)"
                                    : 'No companies deleted')
                                ->body(trim(implode(' ', array_filter([
                                    $contacts > 0 ? "{$contacts} contact(s) removed." : null,
                                    $docs > 0 ? "{$docs} document(s) removed." : null,
                                    $skippedTin > 0 ? "{$skippedTin} skipped (TIN validated)." : null,
                                    $failed > 0 ? "{$failed} failed." : null,
                                ]))) ?: null)
                                ->color($deleted > 0 ? 'success' : 'warning')
                                ->send();
                        }),
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
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Companies are created by partners (create/attach flow).
        return false;
    }

    public static function canDelete($record): bool
    {
        return $record instanceof Company && ! $record->tin_validated;
    }

    public static function canForceDelete($record): bool
    {
        return $record instanceof Company && ! $record->tin_validated;
    }

    public static function canDeleteAny(): bool
    {
        return true;
    }

    public static function canForceDeleteAny(): bool
    {
        return true;
    }
}
