<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\ServiceOperationalStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionProvisioningLogService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->loadMissing(['service', 'company', 'contact']);
    }

    public function getTitle(): string|Htmlable
    {
        return SubscriptionResource::getRecordTitle($this->getRecord()) ?? 'Subscription';
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $bits = array_filter([
            $record->public_id ? 'ID '.$record->public_id : null,
            $record->company?->name,
        ]);

        return $bits !== []
            ? implode(' · ', $bits)
            : 'Details, linked service requests, messages, attachments, and status logs.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('set_uptime')
                ->label('Set uptime status')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->form([
                    Select::make('operational_status')
                        ->label('Uptime status')
                        ->options(collect(ServiceOperationalStatus::cases())
                            ->mapWithKeys(fn (ServiceOperationalStatus $s) => [$s->value => $s->label()])
                            ->all())
                        ->required()
                        ->default(fn (Subscription $record): string => ($record->operational_status instanceof ServiceOperationalStatus
                            ? $record->operational_status
                            : ServiceOperationalStatus::tryFrom((string) ($record->operational_status ?? ''))
                        )?->value ?? ServiceOperationalStatus::Unknown->value)
                        ->helperText('Staff-reported until an external probe is connected.'),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(3),
                ])
                ->action(function (Subscription $record, array $data, SubscriptionProvisioningLogService $logs): void {
                    $logs->setOperationalStatus(
                        $record,
                        ServiceOperationalStatus::from((string) $data['operational_status']),
                        auth()->user(),
                        $data['note'] ?? null,
                    );

                    Notification::make()
                        ->title('Uptime status updated')
                        ->success()
                        ->send();

                    $this->refreshFormData(['operational_status', 'operational_status_updated_at']);
                }),
        ];
    }
}
