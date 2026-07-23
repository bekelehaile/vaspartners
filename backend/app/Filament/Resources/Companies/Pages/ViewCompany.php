<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Services\CompanyMembershipService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
