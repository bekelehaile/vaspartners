<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Services\CompanyPurgeService;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
