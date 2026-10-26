<?php

namespace App\Filament\Resources\ServiceProvisionings\Pages;

use App\Enums\ProvisioningStepStatus;
use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use App\Services\Provisioning\ProvisioningWorkflowRegistry;
use App\Services\Provisioning\ServiceProvisioningService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewServiceProvisioning extends ViewRecord
{
    protected static string $resource = ServiceProvisioningResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ServiceProvisioning $record */
        $record = $this->getRecord();
        $record->loadMissing('steps');

        $actions = [];
        foreach ($record->steps->where('status', ProvisioningStepStatus::Waiting) as $step) {
            $actions[] = $this->stepAction($record, $step);
        }

        return $actions;
    }

    protected function stepAction(ServiceProvisioning $case, ServiceProvisioningStep $step): Action
    {
        $registry = app(ProvisioningWorkflowRegistry::class);
        $def = $registry->step($case->workflow_key, $step->step_key) ?? [];
        $fields = [];

        foreach ($def['form_fields'] ?? [] as $field) {
            $name = $field['name'];
            $label = $field['label'] ?? $name;
            $required = ! empty($field['required']);

            $component = match ($field['type'] ?? 'text') {
                'textarea' => Textarea::make($name)->label($label)->rows(3),
                'url' => TextInput::make($name)->label($label)->url(),
                'password' => TextInput::make($name)->label($label)->password()->revealable(),
                'file' => FileUpload::make($name)->label($label)->disk('local')->directory('provisioning-tmp'),
                'packages' => Repeater::make($name)
                    ->label($label)
                    ->schema([
                        TextInput::make('package')->label('Package')->required(),
                        TextInput::make('price_point')->label('Price point')->required(),
                        TextInput::make('sub_keyword')->label('Subscription keyword')->required(),
                        TextInput::make('unsub_keyword')->label('Un-sub keyword')->required(),
                    ])
                    ->defaultItems(1)
                    ->collapsible(),
                default => TextInput::make($name)->label($label),
            };

            if ($required && ($field['type'] ?? '') !== 'file') {
                $component = $component->required();
            }

            $fields[] = $component;
        }

        $fields[] = Textarea::make('remark')->label('Remark')->rows(2);
        $fields[] = FileUpload::make('attachment')->label('Attachment')->disk('local')->directory('provisioning-tmp');

        return Action::make('act_'.$step->step_key)
            ->label($step->label)
            ->modalHeading($step->label)
            ->modalDescription($step->whereLabel())
            ->form($fields)
            ->action(function (array $data) use ($case, $step): void {
                try {
                    $attachment = null;
                    if (! empty($data['attachment'])) {
                        // Filament may store path string; skip complex rehydrate for now.
                        unset($data['attachment']);
                    }
                    unset($data['service_proposal']);

                    app(ServiceProvisioningService::class)->submitStep(
                        $case,
                        $step->step_key,
                        auth()->user(),
                        $data,
                        $attachment,
                    );

                    Notification::make()->title('Step completed')->success()->send();
                    $this->refreshFormData(['status', 'current_step_key', 'completed_at']);
                    $this->record->refresh()->load('steps');
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Could not complete step')
                        ->body(collect($e->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    throw $e;
                }
            });
    }
}
