<?php

namespace App\Filament\Resources\Tickets\Concerns;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Category;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

trait SendsFilteredTicketSms
{
    protected function sendSmsFilteredHeaderAction(): Action
    {
        return Action::make('send_sms_filtered')
            ->label('Send SMS to filtered')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('primary')
            ->visible(fn (): bool => (bool) auth()->user()?->canBulkSendTicketSms())
            ->disabled(fn (): bool => ! $this->hasTicketAudienceFilter())
            ->tooltip(fn (): ?string => $this->hasTicketAudienceFilter()
                ? null
                : 'Select a tab other than All, or filter by Status / Group first')
            ->modalHeading('Send SMS to filtered tickets')
            ->modalDescription(fn (): string => $this->filteredTicketAudienceDescription())
            ->form([
                Textarea::make('message')
                    ->label('SMS message')
                    ->required()
                    ->rows(5)
                    ->maxLength(640)
                    ->helperText('Queued to each matching ticket contact phone. Max 640 characters.'),
            ])
            ->requiresConfirmation()
            ->action(function (array $data, SmsService $sms): void {
                if (! $this->hasTicketAudienceFilter()) {
                    Notification::make()
                        ->title('Audience filter required')
                        ->body('Use a tab other than All, or filter by Status / Group before sending.')
                        ->warning()
                        ->send();

                    return;
                }

                $query = $this->getFilteredTableQuery();
                if (! $query) {
                    Notification::make()->title('No tickets to message')->warning()->send();

                    return;
                }

                $result = TicketResource::queueSmsToTickets(
                    $query->clone()->reorder()->orderBy('tickets.id')->with('contact'),
                    (string) ($data['message'] ?? ''),
                    $sms,
                );

                Notification::make()
                    ->title($result['queued'] > 0
                        ? "Queued SMS for {$result['queued']} ticket(s)"
                        : 'No SMS queued')
                    ->body($result['skipped'] > 0
                        ? "{$result['skipped']} skipped (missing or invalid contact phone)."
                        : null)
                    ->color($result['queued'] > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    protected function hasTicketAudienceFilter(): bool
    {
        if (($this->activeTab ?? 'all') !== 'all' && filled($this->activeTab ?? null)) {
            return true;
        }

        return filled(data_get($this->tableFilters ?? [], 'status.value'))
            || filled(data_get($this->tableFilters ?? [], 'category_id.value'))
            || filled(data_get($this->tableFilters ?? [], 'attachments.value'));
    }

    protected function filteredTicketAudienceDescription(): string
    {
        $bits = [];
        if (($this->activeTab ?? 'all') !== 'all' && filled($this->activeTab ?? null)) {
            $bits[] = 'Tab: '.str_replace('_', ' ', (string) $this->activeTab);
        }

        $status = data_get($this->tableFilters ?? [], 'status.value');
        if (filled($status)) {
            $bits[] = 'Status: '.$status;
        }

        $categoryId = data_get($this->tableFilters ?? [], 'category_id.value');
        if (filled($categoryId)) {
            $name = Category::query()->whereKey((int) $categoryId)->value('name') ?: '#'.$categoryId;
            $bits[] = 'Group: '.$name;
        }

        $attachments = data_get($this->tableFilters ?? [], 'attachments.value');
        if (filled($attachments)) {
            $bits[] = 'Attachments: '.$attachments;
        }

        $count = $this->getFilteredTableQuery()?->count() ?? 0;
        $scope = $bits !== [] ? implode(' · ', $bits) : 'current filters';

        return "Audience: {$scope}. About {$count} ticket(s) in the current filtered list. SMS goes to each ticket contact phone.";
    }
}
