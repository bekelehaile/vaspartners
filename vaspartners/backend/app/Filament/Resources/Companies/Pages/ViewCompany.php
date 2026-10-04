<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\CompanyPurgeService;
use App\Services\ConsolidateMvasIntoVerifiedTinService;
use App\Services\RemountSubscriptionsToVerifiedTinService;
use App\Services\SmsService;
use App\Support\TinNumber;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => CompanyResource::canEdit($this->getRecord())),
            ActionGroup::make([
                Action::make('change_company_contact')
                    ->label('Change contact')
                    ->icon('heroicon-o-user-plus')
                    ->color('warning')
                    ->visible(fn (): bool => (bool) $this->getRecord()->erca_tin_verified
                        && CompanyResource::canEdit($this->getRecord()))
                    ->url(fn (): string => CompanyResource::getUrl('change-contact', ['record' => $this->getRecord()])),
                Action::make('send_sms')
                    ->label('Send SMS')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth()->user()?->canSendCompanySms()
                        && filled($this->getRecord()->claimPhone())
                        && (auth()->user()?->canHandleCompanyServices($this->getRecord()) ?? false))
                    ->form([
                        Textarea::make('message')
                            ->label('SMS message')
                            ->required()
                            ->rows(5)
                            ->maxLength(640)
                            ->helperText('Event / ad-hoc SMS to this company phone. Max 640 characters.'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => 'Send SMS to '.$this->getRecord()->name)
                    ->action(function (array $data, SmsService $sms): void {
                        CompanyResource::dispatchCompanySms(
                            $this->getRecord(),
                            (string) ($data['message'] ?? ''),
                            $sms,
                        );
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => CompanyResource::canDelete($this->getRecord()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => 'Delete company '.$this->getRecord()->name)
                    ->modalDescription(
                        'Permanently deletes this company and related memberships, tickets, subscriptions, and orphan contacts. Companies with an owner, verified TIN number, and at least one subscription cannot be deleted.'
                    )
                    ->modalSubmitActionLabel('Delete permanently')
                    ->action(function (CompanyPurgeService $purge): void {
                        /** @var Company $record */
                        $record = $this->getRecord();
                        $result = CompanyResource::purgeCompanyRecord($record, $purge);
                        if ($result['ok']) {
                            $this->redirect(CompanyResource::getUrl('index'));
                        }
                    }),
            ])
                ->label('Manage')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->button(),
            ActionGroup::make([
                Action::make('import_services')
                    ->label('Import services')
                    ->icon(Heroicon::ArrowDownOnSquareStack)
                    ->color('success')
                    ->visible(fn (): bool => $this->canManageLegacyMerge())
                    ->modalHeading('Import services from legacy company')
                    ->modalDescription('Move subscriptions from an unverified / MVAS company onto this verified TIN company.')
                    ->modalIcon(Heroicon::ArrowDownOnSquareStack)
                    ->modalIconColor('success')
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel('Import services')
                    ->form(fn (): array => $this->legacySourceForm(previewMode: 'import'))
                    ->action(function (
                        array $data,
                        RemountSubscriptionsToVerifiedTinService $remount,
                    ): void {
                        /** @var Company $verified */
                        $verified = $this->getRecord();
                        $source = Company::query()->findOrFail((int) $data['source_company_id']);

                        try {
                            $preview = $remount->importFromCompany($verified, $source, dryRun: true);
                            if ($preview['moved'] === 0 && $preview['skipped'] === 0) {
                                Notification::make()
                                    ->title('Nothing to import')
                                    ->body('That company has no subscriptions to move.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $result = $remount->importFromCompany(
                                $verified,
                                $source,
                                dryRun: false,
                                actor: auth()->user(),
                            );
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Cannot import services')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();

                            throw $e;
                        }

                        Notification::make()
                            ->title('Services imported')
                            ->body(sprintf(
                                'Moved %d subscription(s); skipped %d conflict(s). You can remove the legacy company when it has no alive services left.',
                                $result['moved'],
                                $result['skipped'],
                            ))
                            ->success()
                            ->send();

                        $this->refreshFormData(['legacy_mvas_id']);
                    }),
                Action::make('remove_legacy_company')
                    ->label('Remove legacy company')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->visible(fn (): bool => $this->canManageLegacyMerge())
                    ->modalHeading('Remove legacy / unverified company')
                    ->modalDescription('Merge leftover records into this verified company, then soft-delete the legacy shell. Prefer Import services first.')
                    ->modalIcon(Heroicon::Trash)
                    ->modalIconColor('danger')
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel('Merge & remove')
                    ->requiresConfirmation()
                    ->form(fn (): array => [
                        ...$this->legacySourceForm(previewMode: 'remove'),
                        Toggle::make('purge_permanently')
                            ->label('Permanently delete instead of soft-delete')
                            ->helperText('Only when the legacy company has no alive subscriptions. Super-admin recommended.')
                            ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin'))
                            ->default(false),
                    ])
                    ->action(function (
                        array $data,
                        ConsolidateMvasIntoVerifiedTinService $consolidate,
                        CompanyPurgeService $purge,
                        RemountSubscriptionsToVerifiedTinService $remount,
                    ): void {
                        /** @var Company $verified */
                        $verified = $this->getRecord();
                        $source = Company::query()->findOrFail((int) $data['source_company_id']);

                        try {
                            $remount->assertVerifiedTarget($verified);
                            if (! $remount->isLegacySource($source)) {
                                throw ValidationException::withMessages([
                                    'source_company_id' => 'Source must be an unverified or MVAS placeholder company.',
                                ]);
                            }
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Cannot remove legacy company')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();

                            throw $e;
                        }

                        $aliveCount = $this->aliveSubscriptionCount($source);
                        $purgePermanently = (bool) ($data['purge_permanently'] ?? false)
                            && (bool) auth()->user()?->hasRole('super_admin');

                        if ($purgePermanently) {
                            if ($aliveCount > 0) {
                                Notification::make()
                                    ->title('Cannot permanently delete')
                                    ->body('Import alive services first. This company still has '.$aliveCount.' alive subscription(s).')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $result = CompanyResource::purgeCompanyRecord($source, $purge);
                            if ($result['ok']) {
                                Notification::make()
                                    ->title('Legacy company permanently deleted')
                                    ->success()
                                    ->send();
                            }

                            return;
                        }

                        if ($aliveCount > 0) {
                            Notification::make()
                                ->title('Alive services still on legacy company')
                                ->body('Import services first, or permanently purge only after moving alive subscriptions. Alive: '.$aliveCount.'.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = $consolidate->consolidatePair(
                            (int) $source->id,
                            (int) $verified->id,
                            dryRun: false,
                        );

                        $counts = $result['moved'] ?? [];
                        Notification::make()
                            ->title('Legacy company merged & removed')
                            ->body(sprintf(
                                '%s soft-deleted. Moved subscriptions %d, memberships %d, change requests %d.',
                                $source->tin,
                                (int) ($counts['subscriptions'] ?? 0),
                                (int) ($counts['memberships'] ?? 0),
                                (int) ($counts['change_requests'] ?? 0),
                            ))
                            ->success()
                            ->send();

                        $this->refreshFormData(['legacy_mvas_id']);
                    }),
            ])
                ->label('Legacy')
                ->icon(Heroicon::ArrowDownOnSquareStack)
                ->color('success')
                ->button()
                ->visible(fn (): bool => $this->canManageLegacyMerge()),
        ];
    }

    protected function canManageLegacyMerge(): bool
    {
        $record = $this->getRecord();

        return CompanyResource::canEdit($record)
            && (bool) $record->erca_tin_verified
            && (bool) $record->tin_validated
            && TinNumber::isValid((string) $record->tin);
    }

    /**
     * @return list<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected function legacySourceForm(string $previewMode): array
    {
        return [
            Select::make('source_company_id')
                ->label('Legacy / unverified company')
                ->options(fn (): array => $this->legacyCompanyOptions())
                ->getSearchResultsUsing(fn (string $search): array => $this->legacyCompanyOptions($search))
                ->getOptionLabelUsing(function ($value): ?string {
                    $company = Company::query()->find($value);
                    if (! $company) {
                        return null;
                    }
                    $alive = $this->aliveSubscriptionCount($company);

                    return sprintf(
                        '%s · TIN %s · alive services %d',
                        $company->name ?: '—',
                        $company->tin ?: '—',
                        $alive,
                    );
                })
                ->searchable()
                ->required()
                ->native(false)
                ->live()
                ->helperText('Search by name or TIN (MVAS placeholder / unverified).'),
            Placeholder::make('source_preview')
                ->label('Preview')
                ->content(function (callable $get) use ($previewMode): HtmlString {
                    $id = (int) ($get('source_company_id') ?? 0);
                    if ($id < 1) {
                        return new HtmlString('<span class="text-sm text-gray-500">Select a company to preview subscriptions.</span>');
                    }

                    $source = Company::query()->find($id);
                    if (! $source) {
                        return new HtmlString('<span class="text-sm text-danger-600">Company not found.</span>');
                    }

                    return new HtmlString($this->legacyPreviewHtml($source, $previewMode));
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function legacyCompanyOptions(?string $search = null): array
    {
        $verifiedId = (int) $this->getRecord()->id;

        $query = Company::query()
            ->where('id', '!=', $verifiedId)
            ->where(function ($q): void {
                $q->where('tin', 'like', 'MVAS-%')
                    ->orWhere('erca_tin_verified', false)
                    ->orWhereNull('erca_tin_verified')
                    ->orWhere('tin_validated', false)
                    ->orWhereNull('tin_validated')
                    ->orWhereRaw("tin !~ '^[0-9]{10}$'");
            })
            ->withCount([
                'subscriptions as alive_subscriptions_count' => fn ($q) => $q->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PendingRenewal->value,
                    SubscriptionStatus::Grace->value,
                ]),
            ]);

        if (filled($search)) {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('legal_name', 'ilike', $term)
                    ->orWhere('tin', 'ilike', $term);
            });
        }

        return $query
            ->orderByDesc('alive_subscriptions_count')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(function (Company $company): array {
                $alive = (int) ($company->alive_subscriptions_count ?? 0);

                return [
                    $company->id => sprintf(
                        '%s · TIN %s · alive services %d',
                        $company->name ?: '—',
                        $company->tin ?: '—',
                        $alive,
                    ),
                ];
            })
            ->all();
    }

    protected function legacyPreviewHtml(Company $source, string $previewMode): string
    {
        $subs = Subscription::withTrashed()
            ->with('service:id,name')
            ->where('company_id', $source->id)
            ->orderBy('id')
            ->get();

        $alive = $this->aliveSubscriptionCount($source);
        $name = e($source->name ?: '—');
        $tin = e($source->tin ?: '—');
        $modeNote = $previewMode === 'remove'
            ? ($alive > 0
                ? '<p class="mt-2 text-sm text-danger-600">Still has '.$alive.' alive subscription(s). Import services first.</p>'
                : '<p class="mt-2 text-sm text-success-700">No alive subscriptions — safe to merge &amp; remove.</p>')
            : '<p class="mt-2 text-sm text-gray-600">Alive subscriptions will move here; conflicts (same service already alive) are skipped.</p>';

        if ($subs->isEmpty()) {
            return <<<HTML
<div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
  <div class="font-semibold text-gray-950 dark:text-white">{$name}</div>
  <div class="text-gray-600 dark:text-gray-300">TIN {$tin}</div>
  <p class="mt-2 text-gray-500">No subscriptions on this company.</p>
  {$modeNote}
</div>
HTML;
        }

        $items = $subs->map(function (Subscription $sub): string {
            $service = e($sub->service?->name ?: 'Service');
            $status = e($sub->status instanceof SubscriptionStatus
                ? $sub->status->label()
                : (string) $sub->status);
            $pid = e($sub->public_id ?: (string) $sub->id);
            $trashed = $sub->trashed() ? ' · deleted' : '';

            return "<li><span class=\"font-medium\">{$service}</span> · {$status} · {$pid}{$trashed}</li>";
        })->implode('');

        return <<<HTML
<div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
  <div class="font-semibold text-gray-950 dark:text-white">{$name}</div>
  <div class="text-gray-600 dark:text-gray-300">TIN {$tin} · {$subs->count()} subscription(s), {$alive} alive</div>
  <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-800 dark:text-gray-200">{$items}</ul>
  {$modeNote}
</div>
HTML;
    }

    protected function aliveSubscriptionCount(Company $company): int
    {
        return Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PendingRenewal->value,
                SubscriptionStatus::Grace->value,
            ])
            ->count();
    }
}
