<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Service;
use App\Services\SmsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    public function getTitle(): string
    {
        return 'Companies';
    }

    public function getSubheading(): ?string
    {
        return 'Filter by service / type for event SMS. For special one-off lists, use Partners → Bulk messages (CSV import).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_sms_filtered')
                ->label('Send SMS to filtered')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('primary')
                ->visible(fn (): bool => (bool) auth()->user()?->canBulkSendCompanySms())
                ->disabled(fn (): bool => ! $this->hasServiceAudienceFilter())
                ->tooltip(fn (): ?string => $this->hasServiceAudienceFilter()
                    ? null
                    : 'Apply Service type and/or Service filter first')
                ->modalHeading('Send SMS to filtered companies')
                ->modalDescription(fn (): string => $this->filteredAudienceDescription())
                ->form([
                    Textarea::make('message')
                        ->label('SMS message')
                        ->required()
                        ->rows(5)
                        ->maxLength(640)
                        ->helperText('Queued to each matching company phone. Max 640 characters.'),
                ])
                ->requiresConfirmation()
                ->action(function (array $data, SmsService $sms): void {
                    if (! $this->hasServiceAudienceFilter()) {
                        Notification::make()
                            ->title('Filters required')
                            ->body('Set Service type and/or Service before sending.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $query = $this->getFilteredTableQuery();
                    if (! $query) {
                        Notification::make()->title('No companies to message')->warning()->send();

                        return;
                    }

                    $result = CompanyResource::queueSmsToCompanies(
                        $query->clone()->reorder()->orderBy('companies.id'),
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
        ];
    }

    public function getTabs(): array
    {
        $base = fn (): Builder => Company::query();

        return [
            'all' => Tab::make('All')->badge(fn (): int => $base()->count()),
            'pending' => Tab::make('Pending approval')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Pending)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Pending)),
            'approved' => Tab::make('Approved')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Approved)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Approved)),
            'orphans' => Tab::make('Orphan (no owner)')
                ->badge(fn (): int => $base()->ownerless()->where('approval_status', CompanyApprovalStatus::Approved)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->ownerless()
                    ->where('approval_status', CompanyApprovalStatus::Approved)),
            'rejected' => Tab::make('Rejected')
                ->badge(fn (): int => $base()->where('approval_status', CompanyApprovalStatus::Rejected)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', CompanyApprovalStatus::Rejected)),
        ];
    }

    protected function hasServiceAudienceFilter(): bool
    {
        $filters = $this->tableFilters ?? [];

        return filled(data_get($filters, 'service_type.value'))
            || filled(data_get($filters, 'service_id.value'));
    }

    protected function filteredAudienceDescription(): string
    {
        $parts = [];
        $type = data_get($this->tableFilters, 'service_type.value');
        $serviceId = data_get($this->tableFilters, 'service_id.value');

        if (filled($type)) {
            $parts[] = 'Type: '.$type;
        }

        if (filled($serviceId)) {
            $name = Service::query()->whereKey($serviceId)->value('name') ?: '#'.$serviceId;
            $parts[] = 'Service: '.$name;
        }

        $count = $this->getFilteredTableQuery()?->count() ?? 0;
        $scope = $parts !== [] ? implode(' · ', $parts) : 'current filters';

        return "Audience: {$scope}. About {$count} company(ies) in the current filtered list (other table filters/tabs also apply).";
    }
}
