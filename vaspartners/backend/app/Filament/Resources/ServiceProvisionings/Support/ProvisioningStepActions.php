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
use Filament\Schemas\Components\Section;
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
     * Read-only follow-up View: metadata + every submitted attribute.
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function viewFormSchema(ServiceProvisioningStep $step): array
    {
        $entries = static::visiblePayloadEntries($step->payload ?? []);
        $sections = [
            Section::make('Step')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('what')->label('What')->disabled()->dehydrated(false),
                        TextInput::make('where')->label('Where')->disabled()->dehydrated(false),
                        TextInput::make('status')->label('Status')->disabled()->dehydrated(false),
                        TextInput::make('days')->label('OLA (days · hours)')->disabled()->dehydrated(false),
                        Textarea::make('remark')->label('Remark')->rows(3)->disabled()->dehydrated(false)->columnSpanFull(),
                    ]),
                ]),
        ];

        foreach (static::payloadSections($entries) as $title => $fields) {
            if ($fields === []) {
                continue;
            }

            $sections[] = Section::make($title)
                ->schema([
                    Grid::make(2)->schema($fields),
                ]);
        }

        return $sections;
    }

    /**
     * Group formatted payload entries into Overview-aligned sections.
     *
     * @param  array<string, string>  $entries
     * @return array<string, list<\Filament\Forms\Components\Component>>
     */
    public static function payloadSections(array $entries): array
    {
        $groups = [
            'Customer' => ['company_name', 'customer_name', 'contact_number', 'contact_email'],
            'Service' => ['service_name', 'service_type'],
            'Resource allocation' => ['short_code', 'data_sync_url'],
            'Commercial' => ['crm_order', 'payer_party_type', 'free_trial_period', 'packages'],
            'Product configuration' => ['service_id', 'product_id', 'partner_id', 'spid', 'port'],
            'Outcome' => ['completion_status'],
        ];

        $assigned = [];
        $sections = [];

        foreach ($groups as $title => $keys) {
            $fields = [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $entries)) {
                    continue;
                }
                $fields[] = static::readOnlyPayloadField($key, $entries[$key]);
                $assigned[$key] = true;
            }
            if ($fields !== []) {
                $sections[$title] = $fields;
            }
        }

        $other = [];
        foreach ($entries as $key => $formatted) {
            if (isset($assigned[$key])) {
                continue;
            }
            $other[] = static::readOnlyPayloadField($key, $formatted);
        }
        if ($other !== []) {
            $sections['Other parameters'] = $other;
        }

        return $sections;
    }

    /**
     * @return \Filament\Forms\Components\Textarea|\Filament\Forms\Components\TextInput
     */
    public static function readOnlyPayloadField(string $key, string $formatted)
    {
        $rows = max(1, min(8, substr_count($formatted, "\n") + 1));
        $field = Textarea::make('attr_'.$key)
            ->label(static::payloadFieldLabel($key))
            ->rows($rows)
            ->disabled()
            ->dehydrated(false);

        if ($rows > 1 || in_array($key, ['packages', 'data_sync_url'], true)) {
            return $field->columnSpanFull();
        }

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    public static function viewFormState(ServiceProvisioningStep $step): array
    {
        $state = [
            'what' => $step->label,
            'where' => $step->whereLabel(),
            'status' => $step->status?->label() ?? '—',
            'days' => $step->elapsedOlaLabel().' ('.$step->hoursInStep().' hour(s))',
            'remark' => filled($step->remark) ? (string) $step->remark : '—',
        ];

        foreach (static::visiblePayloadEntries($step->payload ?? []) as $key => $formatted) {
            $state['attr_'.$key] = $formatted;
        }

        return $state;
    }

    /**
     * Flatten payload for display; hide secrets / internal flags.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function visiblePayloadEntries(array $payload): array
    {
        $hidden = [
            'credentials',
            'credentials_released_to_partner',
            'credentials_released_at',
            'smsc_password',
            'smsc_username',
            'partner_messaging_closed_at',
            'partner_messaging_closed_reason',
        ];

        $preferred = [
            'short_code',
            'data_sync_url',
            'crm_order',
            'payer_party_type',
            'free_trial_period',
            'packages',
            'service_id',
            'product_id',
            'partner_id',
            'spid',
            'port',
            'completion_status',
            'company_name',
            'customer_name',
            'contact_number',
            'contact_email',
            'service_name',
            'service_type',
        ];

        $entries = [];
        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if (in_array($key, $hidden, true)) {
                continue;
            }
            $formatted = static::formatPayloadValue($key, $value);
            if ($formatted === null) {
                continue;
            }
            $entries[$key] = $formatted;
        }

        uksort($entries, function (string $a, string $b) use ($preferred): int {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);
            $ia = $ia === false ? 1000 : $ia;
            $ib = $ib === false ? 1000 : $ib;
            if ($ia !== $ib) {
                return $ia <=> $ib;
            }

            return strcasecmp($a, $b);
        });

        return $entries;
    }

    public static function payloadFieldLabel(string $key): string
    {
        return match ($key) {
            'short_code' => 'Short code',
            'data_sync_url' => 'Data Sync URL',
            'crm_order' => 'CRM order',
            'payer_party_type' => 'Payer party',
            'free_trial_period' => 'Free trial period (days)',
            'packages' => 'Subscription packages',
            'service_id' => 'Service ID',
            'product_id' => 'Product ID',
            'partner_id' => 'Partner ID',
            'spid' => 'SPID',
            'port' => 'Port',
            'completion_status' => 'Status',
            'company_name' => 'Company name',
            'customer_name' => 'Customer name',
            'contact_number' => 'Contact number',
            'contact_email' => 'Contact email',
            'service_name' => 'Service name',
            'service_type' => 'Service type',
            default => str_replace('_', ' ', ucfirst($key)),
        };
    }

    public static function formatPayloadValue(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if ($key === 'free_trial_period' && is_numeric($value)) {
            return ((int) $value).' day(s)';
        }

        if ($key === 'packages' && is_array($value)) {
            $lines = collect($value)
                ->map(function ($row): ?string {
                    if (! is_array($row)) {
                        return filled($row) ? (string) $row : null;
                    }

                    $parts = collect([
                        $row['package'] ?? null,
                        isset($row['tariff']) ? 'tariff '.$row['tariff'] : null,
                        isset($row['price_point']) ? 'price '.$row['price_point'] : null,
                        isset($row['sub_keyword']) ? 'sub '.$row['sub_keyword'] : null,
                        isset($row['unsub_keyword']) ? 'unsub '.$row['unsub_keyword'] : null,
                    ])->filter()->values();

                    return $parts->isEmpty() ? null : $parts->implode(' · ');
                })
                ->filter()
                ->values();

            return $lines->isEmpty() ? null : $lines->implode("\n");
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || in_array($json, ['[]', '{}', 'null', ''], true)) {
            return null;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function makeFormFieldComponent(array $field): mixed
    {
        $name = $field['name'];
        $label = $field['label'] ?? $name;
        $required = ! empty($field['required']);
        $type = $field['type'] ?? 'text';

        $component = match ($type) {
            'textarea' => Textarea::make($name)->label($label)->rows(3)->columnSpanFull(),
            'url' => TextInput::make($name)->label($label)->url(),
            'password' => TextInput::make($name)->label($label)->password()->revealable(),
            'number' => TextInput::make($name)
                ->label($label)
                ->numeric()
                ->integer()
                ->minValue(isset($field['min']) ? (int) $field['min'] : 0)
                ->suffix('days'),
            'integer' => TextInput::make($name)
                ->label($label)
                ->numeric()
                ->integer()
                ->inputMode('numeric')
                ->rule('integer')
                ->rule('min:0')
                ->minValue(0),
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
                    Select::make('package')
                        ->label('Package')
                        ->required()
                        ->native(false)
                        ->searchable(false)
                        ->options([
                            'Daily' => 'Daily',
                            'Weekly' => 'Weekly',
                            'Monthly' => 'Monthly',
                        ]),
                    TextInput::make('tariff')
                        ->label('Tariff')
                        ->required()
                        ->numeric()
                        ->inputMode('decimal')
                        ->rule('numeric')
                        ->rule('min:0')
                        ->step(0.01),
                    TextInput::make('price_point')->label('Price point')->required(),
                    TextInput::make('sub_keyword')->label('Subscription keyword')->required(),
                    TextInput::make('unsub_keyword')->label('Un-sub keyword')->required(),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => filled($state['package'] ?? null)
                    ? (string) $state['package']
                    : null)
                ->columnSpanFull(),
            default => TextInput::make($name)->label($label),
        };

        if ($required && $type !== 'file') {
            $component = $component->required();
        }

        return $component;
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function formFields(array $def, ?ServiceProvisioning $case = null): array
    {
        $formFields = array_values($def['form_fields'] ?? []);
        $hasGroups = collect($formFields)->contains(fn (array $field): bool => filled($field['group'] ?? null));

        if ($hasGroups) {
            $grouped = [];
            foreach ($formFields as $index => $field) {
                $group = filled($field['group'] ?? null) ? (string) $field['group'] : 'Details';
                $grouped[$group][] = ['index' => $index, 'field' => $field];
            }

            $sections = [];
            foreach ($grouped as $title => $items) {
                $components = [];
                foreach ($items as $item) {
                    $components[] = static::makeFormFieldComponent($item['field']);
                }

                $sections[] = Section::make($title)
                    ->schema([
                        Grid::make(2)->schema($components),
                    ]);
            }

            $sections[] = Section::make('Handoff')
                ->schema([
                    Grid::make(2)->schema(static::handoffFields($def, $case)),
                ]);

            return $sections;
        }

        // Required attributes first, then optional (preserve config order within each group).
        $sorted = collect($formFields)
            ->values()
            ->sortBy(fn (array $field, int $index): array => [
                empty($field['required']) ? 1 : 0,
                $index,
            ])
            ->values()
            ->all();

        $fields = [];
        foreach ($sorted as $field) {
            $fields[] = static::makeFormFieldComponent($field);
        }

        foreach (static::handoffFields($def, $case) as $handoffField) {
            $fields[] = $handoffField;
        }

        return [
            Grid::make(2)->schema($fields),
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function handoffFields(array $def, ?ServiceProvisioning $case = null): array
    {
        $fields = [
            Textarea::make('remark')->label('Remark')->rows(2)->required()->columnSpanFull(),
        ];

        $prefill = $case
            ? app(ServiceProvisioningService::class)->findPrefillAttachment($case, $def)
            : null;

        if ($prefill) {
            $fields[] = TextInput::make('prefilled_proposal')
                ->label((string) ($def['attachment_label'] ?? 'Service proposal'))
                ->default($prefill['label'])
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull();

            return $fields;
        }

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

        return $fields;
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
            ->form(static::formFields($def, $case))
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

            unset($data['attachment'], $data['service_proposal'], $data['prefilled_proposal']);

            $service = app(ServiceProvisioningService::class);
            if (! $attachment instanceof UploadedFile) {
                $def = app(ProvisioningWorkflowRegistry::class)->step($case->workflow_key, $stepKey) ?? [];
                $attachment = $service->makeUploadedFileFromPrefill($case, $def);
            }

            $service->submitStep(
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
