<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyMembershipService;
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
            Action::make('assignOwner')
                ->label('Assign owner')
                ->color('primary')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => ! $this->getRecord()->hasOwner())
                ->modalHeading('Assign owner (manual verification)')
                ->modalDescription('Use for orphan migrated companies when Fayda phone did not auto-claim. Verify the partner identity before assigning.')
                ->form([
                    Select::make('customer_id')
                        ->label('Partner (customer)')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(function (string $search): array {
                            $term = '%'.trim($search).'%';

                            return Customer::query()
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
                                ->mapWithKeys(fn (Customer $c) => [
                                    $c->id => trim($c->name.' · '.($c->phone_number ?: 'no phone').' · '.($c->email ?: 'no email')),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            $c = Customer::query()->find($value);
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
                        $customer = Customer::query()->findOrFail($data['customer_id']);
                        $membership->adminAssignOwner(
                            $this->getRecord(),
                            $customer,
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
                            'created_by_customer_id',
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
