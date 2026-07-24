<?php

namespace App\Filament\Resources\CompanyChangeRequests\Pages;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\CompanyChangeRequestResource;
use App\Models\CompanyChangeRequest;
use App\Services\CompanyMembershipService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewCompanyChangeRequest extends ViewRecord
{
    protected static string $resource = CompanyChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var CompanyChangeRequest $record */
        $record = $this->getRecord();
        $membership = app(CompanyMembershipService::class);

        return [
            Action::make('download_proposal')
                ->label('Download proposal')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $record->type === CompanyChangeType::Detach && $record->hasProposal())
                ->action(function () use ($record, $membership) {
                    $meta = $membership->downloadPath($record, 'proposal');
                    abort_unless($meta && Storage::disk($meta['disk'])->exists($meta['path']), 404);

                    return Storage::disk($meta['disk'])->download($meta['path'], $meta['name']);
                }),
            Action::make('download_letter')
                ->label('Download letter')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => in_array($record->type, [CompanyChangeType::Detach, CompanyChangeType::TransferOwnership], true)
                    && $record->hasLetter())
                ->action(function () use ($record, $membership) {
                    $meta = $membership->downloadPath($record, 'letter');
                    abort_unless($meta && Storage::disk($meta['disk'])->exists($meta['path']), 404);

                    return Storage::disk($meta['disk'])->download($meta['path'], $meta['name']);
                }),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn () => $record->status === CompanyChangeStatus::Pending
                    && $record->type === CompanyChangeType::TransferOwnership)
                ->form([
                    Textarea::make('admin_note')->label('Note to partner (optional)'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Approve ownership transfer')
                ->modalDescription('The selected member becomes the sole owner. The current owner becomes a member.')
                ->action(function (array $data) use ($record, $membership) {
                    $membership->approve($record, auth()->user(), $data['admin_note'] ?? null);
                    $this->refreshFormData(['status', 'admin_note', 'reviewed_at', 'reviewed_by_user_id', 'reviewed_by_customer_id']);
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn () => $record->status === CompanyChangeStatus::Pending
                    && $record->type === CompanyChangeType::TransferOwnership)
                ->form([
                    Textarea::make('admin_note')->label('Reason for partner')->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) use ($record, $membership) {
                    $membership->reject($record, auth()->user(), $data['admin_note'] ?? null);
                    $this->refreshFormData(['status', 'admin_note', 'reviewed_at', 'reviewed_by_user_id', 'reviewed_by_customer_id']);
                }),
        ];
    }
}
