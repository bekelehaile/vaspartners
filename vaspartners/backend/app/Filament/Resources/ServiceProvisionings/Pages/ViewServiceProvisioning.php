<?php

namespace App\Filament\Resources\ServiceProvisionings\Pages;

use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use App\Filament\Resources\ServiceProvisionings\Support\ProvisioningStepActions;
use App\Models\ServiceProvisioning;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceProvisioning extends ViewRecord
{
    protected static string $resource = ServiceProvisioningResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    protected function getHeaderActions(): array
    {
        /** @var ServiceProvisioning $record */
        $record = $this->getRecord();
        $record->loadMissing('steps');

        $stepActions = ProvisioningStepActions::forCase(
            $record,
            auth()->user(),
            function (): void {
                $this->refreshFormData(['status', 'current_step_key', 'completed_at']);
                $this->record->refresh()->load('steps');
            },
        );

        if ($stepActions === []) {
            return [];
        }

        if (count($stepActions) === 1) {
            return $stepActions;
        }

        return [
            ActionGroup::make($stepActions)
                ->label('Section actions')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->button(),
        ];
    }
}
