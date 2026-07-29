<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Contact;
use App\Services\CompanyMembershipService;
use App\Services\CompanyPurgeService;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Company $record */
        $record = $this->getRecord();

        return [
            EditAction::make()
                ->label('Update company'),
            Action::make('force_purge')
                ->label('Delete permanently')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Permanently delete company?')
                ->modalDescription('Permanently deletes this company, memberships, subscriptions, service requests, attachments, and contacts that only belong here. Cannot be undone.')
                ->action(function (CompanyPurgeService $purge): void {
                    try {
                        $stats = $purge->forcePurge($this->getRecord());
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
                        $this->redirect(CompanyResource::getUrl('index'));
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
                ->visible(fn (): bool => (bool) auth()->user()?->canSendCompanySms()
                    && filled($this->getRecord()->phone))
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
            Action::make('validate_tin')
                ->label('Validate TIN')
                ->icon('heroicon-o-identification')
                ->color('success')
                ->visible(fn (): bool => filled($this->getRecord()->tin) && ! $this->getRecord()->tin_validated)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Validate TIN '.$this->getRecord()->tin.'?')
                ->modalDescription('Confirm this Ethiopian TIN was verified. Partners can submit service requests only after TIN validation.')
                ->action(function (CompanyMembershipService $membership): void {
                    try {
                        $membership->markTinValidated($this->getRecord());
                        Notification::make()->title('TIN validated')->success()->send();
                        $this->refreshFormData(['tin_validated', 'tin']);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Could not validate TIN')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('assignOwner')
                ->label('Assign owner')
                ->color('primary')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => ! $this->getRecord()->hasOwner())
                ->modalHeading('Assign owner (manual verification)')
                ->modalDescription('Use for orphan migrated companies when Fayda phone did not auto-claim. Verify the partner identity before assigning.')
                ->form([
                    Select::make('contact_id')
                        ->label('Partner (contact)')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(function (string $search): array {
                            $term = '%'.trim($search).'%';

                            return Contact::query()
                                ->where('is_active', true)
                                ->where('is_banned', false)
                                ->where(function ($q) use ($term) {
                                    $q->where('name', 'ilike', $term)
                                        ->orWhere('phone_number', 'ilike', $term)
                                        ->orWhere('email', 'ilike', $term);
                                })
                                ->orderBy('name')
                                ->limit(40)
                                ->get()
                                ->mapWithKeys(fn (Contact $c) => [
                                    $c->id => trim($c->name.' · '.($c->phone_number ?: 'no phone').' · '.($c->email ?: 'no email')),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            $c = Contact::query()->find($value);
                            if (! $c) {
                                return null;
                            }

                            return trim($c->name.' · '.($c->phone_number ?: 'no phone'));
                        }),
                    Textarea::make('approval_note')
                        ->label('Verification note')
                        ->helperText('Record how identity was verified (call, letter, ID, etc.).')
                        ->rows(3),
                ])
                ->requiresConfirmation()
                ->action(function (array $data, CompanyMembershipService $membership): void {
                    try {
                        $contact = Contact::query()->findOrFail($data['contact_id']);
                        $membership->adminAssignOwner(
                            $this->getRecord(),
                            $contact,
                            auth()->user(),
                            $data['approval_note'] ?? null,
                        );
                        Notification::make()->title('Owner assigned')->success()->send();
                        $this->refreshFormData([
                            'approval_status',
                            'is_active',
                            'approved_at',
                            'approval_note',
                            'approved_by_user_id',
                            'created_by_contact_id',
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not assign owner')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('approve')
                ->label('Approve profile')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => ! $record->isApproved())
                ->form([
                    Textarea::make('approval_note')->label('Note to partner (optional)'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Approve company profile')
                ->modalDescription('Confirm all required company information is complete. The creating partner remains the owner.')
                ->action(function (array $data, CompanyMembershipService $membership): void {
                    try {
                        $membership->approveCompany($this->getRecord(), auth()->user(), $data['approval_note'] ?? null);
                        Notification::make()->title('Company approved')->success()->send();
                        $this->refreshFormData([
                            'approval_status',
                            'is_active',
                            'approved_at',
                            'approval_note',
                            'approved_by_user_id',
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reject')
                ->label('Reject / request fixes')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => ! $record->isApproved()
                    && $record->approval_status !== CompanyApprovalStatus::Rejected)
                ->form([
                    Textarea::make('approval_note')->label('What is missing / needs correction')->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data, CompanyMembershipService $membership): void {
                    try {
                        $membership->rejectCompany($this->getRecord(), auth()->user(), $data['approval_note'] ?? null);
                        Notification::make()->title('Company rejected')->warning()->send();
                        $this->refreshFormData([
                            'approval_status',
                            'is_active',
                            'approved_at',
                            'approval_note',
                            'approved_by_user_id',
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
