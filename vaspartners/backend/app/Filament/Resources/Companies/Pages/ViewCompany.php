<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Services\CompanyMembershipService;
use App\Services\CompanyPurgeService;
use App\Services\ContactIdentityService;
use App\Services\SmsService;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => CompanyResource::canEdit($this->getRecord())),
            Action::make('change_company_contact')
                ->label('Change contact')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->getRecord()->erca_tin_verified
                    && CompanyResource::canEdit($this->getRecord()))
                ->modalHeading('Change contact')
                ->modalDescription(function (): string {
                    /** @var Company $company */
                    $company = $this->getRecord();
                    $owner = $company->ownerContact();
                    $current = $owner
                        ? trim(($owner->name ?: 'Owner').' / '.($owner->phone_number ?: '—'))
                        : 'No owner';

                    return 'Current: '.$current
                        .'. Enter the new partner phone and save. Existing contacts are used as-is; new phones are looked up in CRM. Old owner is disabled on this company only.';
                })
                ->modalSubmitActionLabel('Save')
                ->form([
                    TextInput::make('phone')
                        ->label('New contact phone')
                        ->tel()
                        ->required()
                        ->maxLength(32)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $normalized = PhoneNumber::normalizeNullable((string) ($state ?? ''));
                            $set('preview_phone', $normalized);
                            $set('preview_name', null);
                            $set('preview_source', null);
                            $set('preview_message', null);

                            if (! filled($normalized) || ! PhoneNumber::isValidEthioTelecomMobile($normalized)) {
                                $set('preview_message', 'Enter a valid Ethio telecom mobile number.');

                                return;
                            }

                            $existing = \App\Models\Contact::query()
                                ->where('phone_number', $normalized)
                                ->first();
                            if ($existing) {
                                $set('preview_name', $existing->name ?: 'Partner');
                                $set('preview_source', 'existing');
                                $set('preview_message', null);

                                return;
                            }

                            $preview = app(ContactIdentityService::class)->previewCrmPhone($normalized);
                            $set('preview_name', $preview['name']);
                            $set('preview_source', $preview['found'] ? 'crm' : 'none');
                            $set('preview_message', $preview['found'] ? null : ($preview['message'] ?: 'Not found in contacts or CRM.'));
                        })
                        ->helperText('Existing partner phone → save directly. New phone → must exist in CRM.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state)),
                    Hidden::make('preview_name')->dehydrated(false),
                    Hidden::make('preview_phone')->dehydrated(false),
                    Hidden::make('preview_source')->dehydrated(false),
                    Hidden::make('preview_message')->dehydrated(false),
                    Placeholder::make('contact_preview')
                        ->label('Contact')
                        ->content(function (Get $get): string {
                            if (! filled($get('phone'))) {
                                return 'Enter a phone number.';
                            }
                            if ($get('preview_source') === 'existing') {
                                return 'Existing contact: '.((string) $get('preview_name')).' ('.((string) $get('preview_phone')).')';
                            }
                            if ($get('preview_source') === 'crm') {
                                return 'CRM: '.((string) $get('preview_name')).' ('.((string) $get('preview_phone')).') — will be created on save.';
                            }

                            return (string) ($get('preview_message') ?: 'Looking up…');
                        }),
                ])
                ->action(function (array $data, CompanyMembershipService $membership): void {
                    /** @var Company $record */
                    $record = $this->getRecord();
                    try {
                        $membership->adminChangeCompanyContact(
                            $record,
                            (string) ($data['phone'] ?? ''),
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Contact saved')
                            ->success()
                            ->send();

                        $this->refreshFormData([
                            'claim_phone',
                            'phone',
                            'revenue_phone',
                            'erca_phone',
                        ]);
                        $this->dispatch('$refresh');
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not change contact')
                            ->body(collect($e->errors())->flatten()->first() ?: $e->getMessage())
                            ->danger()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Could not change contact')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
        ];
    }
}
