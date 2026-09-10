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
                ->label('Change company contact')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->getRecord()->erca_tin_verified
                    && CompanyResource::canEdit($this->getRecord()))
                ->modalHeading('Change company contact')
                ->modalDescription(function (): string {
                    /** @var Company $company */
                    $company = $this->getRecord();
                    $owner = $company->ownerContact();
                    $current = $owner
                        ? trim(($owner->name ?: 'Owner').' / '.($owner->phone_number ?: '—'))
                        : 'No owner';

                    return 'Current owner: '.$current
                        .'. New phone must match CRM. Old owner access on this company will be disabled. Revenue and ERCA phones are unchanged.';
                })
                ->modalSubmitActionLabel('Commit contact change')
                ->form([
                    TextInput::make('phone')
                        ->label('New contact phone')
                        ->tel()
                        ->required()
                        ->maxLength(32)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, ContactIdentityService $identity): void {
                            $preview = $identity->previewCrmPhone((string) ($state ?? ''));
                            $set('crm_found', $preview['found']);
                            $set('crm_name', $preview['name']);
                            $set('crm_message', $preview['message']);
                            $set('crm_phone', $preview['phone']);
                        })
                        ->helperText('Ethio telecom mobile. CRM match is required before commit.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state)),
                    Hidden::make('crm_found')->dehydrated(false),
                    Hidden::make('crm_name')->dehydrated(false),
                    Hidden::make('crm_message')->dehydrated(false),
                    Hidden::make('crm_phone')->dehydrated(false),
                    Placeholder::make('crm_preview')
                        ->label('CRM match')
                        ->content(function (Get $get): string {
                            if (! filled($get('phone'))) {
                                return 'Enter a phone number to look up in CRM.';
                            }
                            if ($get('crm_found')) {
                                return 'Found: '.((string) $get('crm_name')).' ('.((string) $get('crm_phone')).')';
                            }

                            return (string) ($get('crm_message') ?: 'No CRM match yet.');
                        }),
                    Textarea::make('note')
                        ->label('Admin note')
                        ->rows(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data, CompanyMembershipService $membership): void {
                    /** @var Company $record */
                    $record = $this->getRecord();
                    try {
                        $membership->adminChangeCompanyContact(
                            $record,
                            (string) ($data['phone'] ?? ''),
                            auth()->user(),
                            isset($data['note']) ? (string) $data['note'] : null,
                        );

                        Notification::make()
                            ->title('Company contact changed')
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
                            ->title('Could not change company contact')
                            ->body(collect($e->errors())->flatten()->first() ?: $e->getMessage())
                            ->danger()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Could not change company contact')
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
