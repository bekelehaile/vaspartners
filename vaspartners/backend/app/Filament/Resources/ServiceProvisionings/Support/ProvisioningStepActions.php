<?php

namespace App\Filament\Resources\ServiceProvisionings\Support;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStepStatus;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use App\Models\User;
use App\Services\Provisioning\ProvisioningPermissions;
use App\Services\Provisioning\ProvisioningWorkflowRegistry;
use App\Services\Provisioning\ServiceProvisioningService;
use Illuminate\Support\Facades\Gate;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProvisioningStepActions
{
    /**
     * Department role values the user may act as (multi-department + AM as VAS Sales).
     *
     * @return list<string>
     */
    public static function actorDepartmentRoles(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->canBypassServiceProvisioningScope()) {
            return collect(ProvisioningDepartment::cases())
                ->reject(fn (ProvisioningDepartment $d) => $d === ProvisioningDepartment::Partner)
                ->map(fn (ProvisioningDepartment $d) => $d->value)
                ->values()
                ->all();
        }

        $roles = $user->provisioningDepartments();

        // AM may run VAS Sales steps on assigned cases even without a department attachment.
        if ($user->hasRole(User::ROLE_ACCOUNT_MANAGER)
            && ! in_array(ProvisioningDepartment::VasSales->value, $roles, true)) {
            $roles[] = ProvisioningDepartment::VasSales->value;
        }

        return array_values(array_unique($roles));
    }

    public static function userCanActOnStep(
        ?User $user,
        ServiceProvisioningStep $step,
        ?ServiceProvisioning $case = null,
    ): bool {
        if (! $user || ! $case instanceof ServiceProvisioning) {
            return false;
        }

        $from = $step->from_department?->value ?? (string) $step->from_department;
        if ($from === ProvisioningDepartment::Partner->value) {
            return false;
        }

        // Shield custom action permission (Roles UI under ServiceProvisioning).
        $ability = ProvisioningPermissions::abilityForStep((string) $step->step_key);
        if ($ability === null || ! Gate::forUser($user)->allows($ability, $case)) {
            return false;
        }

        if ($user->canBypassServiceProvisioningScope()) {
            return true;
        }

        if (! $user->canActAsProvisioningDepartment($from)) {
            return false;
        }

        // Explicit department attachment covers that section anywhere.
        if (in_array($from, $user->provisioningDepartments(), true)) {
            return true;
        }

        // AM without vas_sales department: only assigned cases.
        if ($user->hasRole(User::ROLE_ACCOUNT_MANAGER)
            && $from === ProvisioningDepartment::VasSales->value) {
            return $user->ownsServiceProvisioningCase($case);
        }

        return false;
    }

    /**
     * Waiting steps on the case that the current staff user may complete.
     *
     * @return Collection<int, ServiceProvisioningStep>
     */
    public static function actionableSteps(ServiceProvisioning $case, ?User $user = null): Collection
    {
        $user ??= auth()->user();
        $case->loadMissing('steps');

        return $case->steps
            ->filter(fn (ServiceProvisioningStep $step) => $step->status === ProvisioningStepStatus::Waiting)
            ->filter(fn (ServiceProvisioningStep $step) => static::userCanActOnStep($user, $step, $case))
            ->values();
    }

    /**
     * Button / menu label: "Submit to VAS Operation", "Submit to IS Billing", etc.
     */
    public static function submitToLabel(ServiceProvisioningStep|string|null $toOrStep): string
    {
        $to = $toOrStep instanceof ServiceProvisioningStep
            ? ($toOrStep->to_department?->value ?? (string) $toOrStep->to_department)
            : (string) $toOrStep;

        $dept = ProvisioningDepartment::tryFrom($to);
        $name = $dept?->label() ?: (filled($to) ? str_replace('_', ' ', ucwords($to, '_')) : 'next section');

        return 'Submit to '.$name;
    }

    /**
     * ActionGroup button label — fixed; destinations stay on each menu item.
     */
    public static function groupLabel(ServiceProvisioning $case, ?User $user = null): string
    {
        return 'Actions';
    }

    /**
     * One Filament action per waiting step the user can act on for this case.
     *
     * @return list<Action>
     */
    public static function forCase(ServiceProvisioning $case, ?User $user = null, ?callable $afterSuccess = null): array
    {
        $actions = [];
        foreach (static::actionableSteps($case, $user) as $step) {
            $actions[] = static::makeStepAction($case, $step, $afterSuccess);
        }

        return $actions;
    }

    /**
     * Static table/header actions for every known workflow step key.
     * Visibility is scoped per-row to waiting steps the logged-in section can run.
     *
     * @return list<Action>
     */
    public static function forTable(): array
    {
        $registry = app(ProvisioningWorkflowRegistry::class);
        $seen = [];
        $actions = [];

        foreach (config('provisioning_workflows.workflows', []) as $workflowKey => $graph) {
            foreach ($graph['steps'] ?? [] as $def) {
                $stepKey = (string) ($def['key'] ?? '');
                if ($stepKey === '' || isset($seen[$stepKey])) {
                    continue;
                }
                $seen[$stepKey] = true;

                $from = (string) ($def['from'] ?? '');
                if ($from === ProvisioningDepartment::Partner->value) {
                    continue;
                }

                $to = (string) ($def['to'] ?? '');
                $submitLabel = static::submitToLabel($to);

                $actions[] = Action::make('act_'.$stepKey)
                    ->label($submitLabel)
                    ->icon(false)
                    ->color('primary')
                    ->slideOver()
                    ->visible(function (ServiceProvisioning $record) use ($stepKey): bool {
                        $step = $record->steps->firstWhere('step_key', $stepKey);
                        if (! $step instanceof ServiceProvisioningStep) {
                            return false;
                        }
                        if ($step->status !== ProvisioningStepStatus::Waiting) {
                            return false;
                        }

                        return static::userCanActOnStep(auth()->user(), $step, $record);
                    })
                    ->modalHeading($submitLabel)
                    ->modalDescription(fn (ServiceProvisioning $record): string => (string) (
                        $record->steps->firstWhere('step_key', $stepKey)?->whereLabel()
                        ?: ($def['label'] ?? '')
                    ))
                    ->form(function (ServiceProvisioning $record) use ($stepKey, $registry): array {
                        $def = $registry->step($record->workflow_key, $stepKey) ?? [];

                        return static::formFields($def);
                    })
                    ->action(function (ServiceProvisioning $record, array $data) use ($stepKey): void {
                        static::runStep($record, $stepKey, $data);
                    });
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function formFields(array $def): array
    {
        $fields = [];

        // Required attributes first, then optional (stable within each group).
        $sorted = collect($def['form_fields'] ?? [])
            ->values()
            ->sortBy(fn (array $field): array => [
                empty($field['required']) ? 1 : 0,
                (string) ($field['label'] ?? $field['name'] ?? ''),
            ])
            ->values()
            ->all();

        foreach ($sorted as $field) {
            $name = $field['name'];
            $label = $field['label'] ?? $name;
            $required = ! empty($field['required']);

            $type = $field['type'] ?? 'text';

            $component = match ($type) {
                'textarea' => Textarea::make($name)->label($label)->rows(3)->columnSpanFull(),
                'url' => TextInput::make($name)->label($label)->url(),
                'password' => TextInput::make($name)->label($label)->password()->revealable(),
                'select' => Select::make($name)
                    ->label($label)
                    ->options(is_array($field['options'] ?? null) ? $field['options'] : [])
                    ->native(false)
                    ->searchable(false),
                'file' => FileUpload::make($name)
                    ->label($label)
                    ->disk('local')
                    ->directory('provisioning-tmp')
                    ->columnSpanFull(),
                'packages' => Repeater::make($name)
                    ->label($label)
                    ->schema([
                        TextInput::make('package')->label('Package')->required(),
                        TextInput::make('price_point')->label('Price point')->required(),
                        TextInput::make('sub_keyword')->label('Subscription keyword')->required(),
                        TextInput::make('unsub_keyword')->label('Un-sub keyword')->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->collapsible()
                    ->columnSpanFull(),
                default => TextInput::make($name)->label($label),
            };

            if ($required && $type !== 'file') {
                $component = $component->required();
            }

            $fields[] = $component;
        }

        $fields[] = Textarea::make('remark')->label('Remark')->rows(2)->required()->columnSpanFull();

        $attachmentLabel = (string) ($def['attachment_label'] ?? 'Attachment');
        $attachment = FileUpload::make('attachment')
            ->label($attachmentLabel)
            ->disk('local')
            ->directory('provisioning-tmp')
            ->columnSpanFull();
        if (! empty($def['requires_attachment'])) {
            $attachment = $attachment->required();
        }
        $fields[] = $attachment;

        // Action has no ->columns(); wrap fields in a 2-col Grid (textarea/attachment span full).
        return [
            Grid::make(2)->schema($fields),
        ];
    }

    public static function makeStepAction(
        ServiceProvisioning $case,
        ServiceProvisioningStep $step,
        ?callable $afterSuccess = null,
    ): Action {
        $registry = app(ProvisioningWorkflowRegistry::class);
        $def = $registry->step($case->workflow_key, $step->step_key) ?? [];

        $submitLabel = static::submitToLabel($step);

        return Action::make('act_'.$step->step_key)
            ->label($submitLabel)
            ->icon(false)
            ->color('primary')
            ->slideOver()
            ->modalHeading($submitLabel)
            ->modalDescription($step->whereLabel().(filled($step->label) ? ' · '.$step->label : ''))
            ->form(static::formFields($def))
            ->action(function (array $data) use ($case, $step, $afterSuccess): void {
                static::runStep($case, $step->step_key, $data);
                if ($afterSuccess) {
                    $afterSuccess();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function runStep(ServiceProvisioning $case, string $stepKey, array $data): void
    {
        try {
            $attachment = static::resolveUploadedFile($data['attachment'] ?? null)
                ?? static::resolveUploadedFile($data['service_proposal'] ?? null);

            unset($data['attachment'], $data['service_proposal']);

            app(ServiceProvisioningService::class)->submitStep(
                $case,
                $stepKey,
                auth()->user(),
                $data,
                $attachment,
            );

            Notification::make()
                ->title('Step completed')
                ->icon(null)
                ->success()
                ->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not complete step')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->icon(null)
                ->danger()
                ->send();

            throw $e;
        }
    }

    protected static function resolveUploadedFile(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($value)) {
            return null;
        }

        $absolute = $disk->path($value);

        return new UploadedFile(
            $absolute,
            basename($value),
            mime_content_type($absolute) ?: 'application/octet-stream',
            null,
            true,
        );
    }
}
