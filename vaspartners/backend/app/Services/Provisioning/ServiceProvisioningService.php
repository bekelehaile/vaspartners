<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Enums\ProvisioningWorkflow;
use App\Enums\ServiceOperationalStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningMedia;
use App\Models\ServiceProvisioningStep;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionProvisioningLogService;
use App\Support\SecureUploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ServiceProvisioningService
{
    public function __construct(
        protected ProvisioningWorkflowRegistry $registry,
        protected SubscriptionProvisioningLogService $lifecycleLogs,
        protected ProvisioningNotificationService $notifications,
    ) {}

    /**
     * After contract sign-off, open a PRV case waiting on the partner for resource allocation.
     * Idempotent: returns the existing open case when one already exists.
     */
    /**
     * @param  bool  $notifyPartner  When false, caller sends the contract-signed SMS/in-app instead (avoids double notify).
     */
    public function openAfterContract(
        Subscription $subscription,
        ?Model $actor = null,
        bool $notifyPartner = true,
    ): ?ServiceProvisioning {
        $subscription->loadMissing(['service', 'company', 'contact']);

        if ($subscription->status !== SubscriptionStatus::Active) {
            return null;
        }

        if ($subscription->contract_signed_at === null) {
            return null;
        }

        $workflow = $this->resolveWorkflow($subscription);
        if ($workflow === null) {
            return null;
        }

        $existing = ServiceProvisioning::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [
                ProvisioningStatus::Draft->value,
                ProvisioningStatus::InProgress->value,
                ProvisioningStatus::Blocked->value,
            ])
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing->load(['steps', 'service', 'company', 'subscription']);
        }

        return DB::transaction(function () use ($subscription, $actor, $workflow, $notifyPartner) {
            $createdBy = $actor;
            if ($createdBy === null) {
                $createdBy = $subscription->contact;
            }

            $case = ServiceProvisioning::query()->create([
                'subscription_id' => $subscription->id,
                'service_id' => $subscription->service_id,
                'company_id' => $subscription->company_id,
                'workflow_key' => $workflow,
                'status' => ProvisioningStatus::InProgress,
                'started_at' => now(),
                'created_by_type' => $createdBy ? $createdBy::class : null,
                'created_by_id' => $createdBy?->getKey(),
                'shared_payload' => $this->contextSnapshot($subscription),
            ]);

            $this->seedSteps($case);

            $fresh = $case->fresh(['steps', 'service', 'company', 'subscription']);
            if ($notifyPartner) {
                $this->notifications->notifyHandoff($fresh);
            }

            return $fresh;
        });
    }

    public function startFromPartner(
        Subscription $subscription,
        Contact $actor,
        array $input,
        ?UploadedFile $attachment = null,
    ): ServiceProvisioning {
        $subscription->loadMissing(['service', 'company', 'contact']);

        if ($subscription->status !== SubscriptionStatus::Active) {
            throw ValidationException::withMessages([
                'subscription' => 'Provisioning can start only for an active subscription.',
            ]);
        }

        if ($subscription->contract_signed_at === null) {
            throw ValidationException::withMessages([
                'subscription' => 'Contract must be signed before requesting resource allocation.',
            ]);
        }

        $workflow = $this->resolveWorkflow($subscription);
        if ($workflow === null) {
            throw ValidationException::withMessages([
                'subscription' => 'This service has no provisioning workflow configured.',
            ]);
        }

        $shortCode = trim((string) ($input['short_code'] ?? ''));
        if ($shortCode === '') {
            throw ValidationException::withMessages([
                'short_code' => 'Short code is required.',
            ]);
        }

        $remark = trim((string) ($input['remark'] ?? ''));
        if ($remark === '') {
            throw ValidationException::withMessages([
                'remark' => 'Remark is required.',
            ]);
        }

        if (! $attachment instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'attachment' => 'Service activation letter attachment is required.',
            ]);
        }

        $this->assertSecureAttachment(
            $attachment,
            'Service activation letter',
            $this->registry->step($workflow, 'partner_resource_request') ?? [],
        );

        return DB::transaction(function () use ($subscription, $actor, $input, $attachment, $workflow, $shortCode, $remark) {
            $case = ServiceProvisioning::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', [
                    ProvisioningStatus::Draft->value,
                    ProvisioningStatus::InProgress->value,
                    ProvisioningStatus::Blocked->value,
                ])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $case) {
                $case = ServiceProvisioning::query()->create([
                    'subscription_id' => $subscription->id,
                    'service_id' => $subscription->service_id,
                    'company_id' => $subscription->company_id,
                    'workflow_key' => $workflow,
                    'status' => ProvisioningStatus::InProgress,
                    'started_at' => now(),
                    'created_by_type' => $actor::class,
                    'created_by_id' => $actor->getKey(),
                    'shared_payload' => $this->contextSnapshot($subscription),
                ]);
                $this->seedSteps($case);
            }

            $first = $case->steps()->where('step_key', 'partner_resource_request')->lockForUpdate()->firstOrFail();

            if ($first->status === ProvisioningStepStatus::Completed) {
                throw ValidationException::withMessages([
                    'subscription' => 'Resource allocation was already submitted for this provisioning case.',
                ]);
            }

            if ($first->status !== ProvisioningStepStatus::Waiting) {
                throw ValidationException::withMessages([
                    'subscription' => 'An open provisioning case already exists for this subscription.',
                ]);
            }

            $this->completeStep(
                $case,
                $first,
                $actor,
                [
                    'short_code' => $shortCode,
                    'data_sync_url' => trim((string) ($input['data_sync_url'] ?? '')) ?: null,
                ],
                $remark,
                $attachment,
            );

            return $case->fresh(['steps', 'service', 'company', 'subscription']);
        });
    }

    public function submitStep(
        ServiceProvisioning $case,
        string $stepKey,
        Model $actor,
        array $input,
        ?UploadedFile $attachment = null,
    ): ServiceProvisioning {
        return DB::transaction(function () use ($case, $stepKey, $actor, $input, $attachment) {
            $case = ServiceProvisioning::query()->lockForUpdate()->findOrFail($case->id);
            $step = $case->steps()->where('step_key', $stepKey)->lockForUpdate()->firstOrFail();

            if ($step->status !== ProvisioningStepStatus::Waiting) {
                throw ValidationException::withMessages([
                    'step' => 'This step is not waiting for action.',
                ]);
            }

            $this->assertActorCanAct($case, $step, $actor);

            $def = $this->registry->step($case->workflow_key, $stepKey) ?? [];
            $payload = $this->validateAndBuildPayload($case, $def, $input);

            $remark = trim((string) ($input['remark'] ?? ''));
            if ($remark === '') {
                throw ValidationException::withMessages([
                    'remark' => 'Remark is required.',
                ]);
            }

            if (! empty($def['requires_attachment'])) {
                $label = (string) ($def['attachment_label'] ?? 'Attachment');
                if (! $attachment instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'attachment' => $label.' is required.',
                    ]);
                }
                $this->assertSecureAttachment($attachment, $label, $def);
            } elseif ($attachment instanceof UploadedFile) {
                $this->assertSecureAttachment(
                    $attachment,
                    (string) ($def['attachment_label'] ?? 'Attachment'),
                    $def,
                );
            }

            // Required file-type form fields (e.g. service proposal) must be uploaded.
            foreach ($def['form_fields'] ?? [] as $field) {
                if (($field['type'] ?? '') !== 'file' || empty($field['required'])) {
                    continue;
                }
                if (! $attachment instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        $field['name'] => ($field['label'] ?? $field['name']).' is required.',
                    ]);
                }
            }

            $this->completeStep(
                $case,
                $step,
                $actor,
                $payload,
                $remark,
                $attachment,
            );

            return $case->fresh(['steps', 'service', 'company', 'subscription']);
        });
    }

    protected function completeStep(
        ServiceProvisioning $case,
        ServiceProvisioningStep $step,
        Model $actor,
        array $payload,
        ?string $remark,
        ?UploadedFile $attachment,
    ): void {
        $def = $this->registry->step($case->workflow_key, $step->step_key) ?? [];

        $mergedShared = array_merge($case->shared_payload ?? [], $payload);
        if (! empty($def['stores_credentials'])) {
            $mergedShared['credentials'] = array_intersect_key($payload, array_flip([
                'service_id', 'product_id', 'partner_id', 'spid',
                'smsc_username', 'smsc_password', 'port',
            ]));
            $mergedShared['credentials_released_to_partner'] = false;
        }

        if (! empty($def['releases_credentials_to_partner'])) {
            $mergedShared['credentials_released_to_partner'] = true;
            $mergedShared['credentials_released_at'] = now()->toIso8601String();
        }

        $partnerCancelled = ($payload['completion_status'] ?? null) === 'cancelled'
            && ($step->from_department?->value === 'partner');

        $step->forceFill([
            'status' => $partnerCancelled
                ? ProvisioningStepStatus::Rejected
                : ProvisioningStepStatus::Completed,
            'payload' => array_merge($this->tableSnapshot($case), $payload),
            'remark' => filled($remark) ? trim((string) $remark) : null,
            'completed_by_type' => $actor::class,
            'completed_by_id' => $actor->getKey(),
            'completed_at' => now(),
        ])->save();

        if ($attachment) {
            $this->storeAttachment($step, $actor, $attachment);
        }

        if (! empty($def['closes_partner_messaging'])) {
            $mergedShared['partner_messaging_closed_at'] = now()->toIso8601String();
            $mergedShared['partner_messaging_closed_reason'] = $partnerCancelled
                ? 'cancelled'
                : 'completed';
        }

        $case->forceFill([
            'shared_payload' => $mergedShared,
        ])->save();

        if ($partnerCancelled) {
            $this->markCancelled($case, $actor);

            return;
        }

        if (! empty($def['is_go_live'])) {
            $this->markGoLive($case, $actor);

            return;
        }

        $this->advance($case);
        $this->notifications->notifyHandoff($case->fresh(['steps', 'company', 'service']));
    }

    protected function markCancelled(ServiceProvisioning $case, Model $actor): void
    {
        unset($actor);

        $case->steps()
            ->whereIn('status', [
                ProvisioningStepStatus::Pending->value,
                ProvisioningStepStatus::Waiting->value,
            ])
            ->update(['status' => ProvisioningStepStatus::Skipped->value]);

        $case->forceFill([
            'status' => ProvisioningStatus::Cancelled,
            'completed_at' => now(),
        ])->save();
    }

    protected function advance(ServiceProvisioning $case): void
    {
        $steps = $this->registry->steps($case->workflow_key);
        $byKey = $case->steps()->get()->keyBy('step_key');

        foreach ($steps as $def) {
            $key = $def['key'];
            /** @var ServiceProvisioningStep $row */
            $row = $byKey->get($key);
            if (! $row || $row->status === ProvisioningStepStatus::Completed
                || $row->status === ProvisioningStepStatus::Skipped) {
                continue;
            }

            if ($waitGroup = $def['waits_for_parallel_group'] ?? null) {
                $siblings = $case->steps()
                    ->where('parallel_group', $waitGroup)
                    ->get();
                $allDone = $siblings->every(
                    fn (ServiceProvisioningStep $s) => $s->status === ProvisioningStepStatus::Completed
                );
                if (! $allDone) {
                    $case->forceFill(['current_step_key' => $case->current_step_key])->save();

                    return;
                }
            }

            if ($row->status === ProvisioningStepStatus::Pending) {
                $group = $def['parallel_group'] ?? null;
                if ($group) {
                    // Open all pending siblings in the same parallel group together.
                    $case->steps()
                        ->where('parallel_group', $group)
                        ->where('status', ProvisioningStepStatus::Pending)
                        ->update(['status' => ProvisioningStepStatus::Waiting->value]);
                } else {
                    $row->forceFill(['status' => ProvisioningStepStatus::Waiting])->save();
                }

                $case->forceFill([
                    'current_step_key' => $key,
                    'status' => ProvisioningStatus::InProgress,
                ])->save();

                return;
            }

            if ($row->status === ProvisioningStepStatus::Waiting) {
                $case->forceFill(['current_step_key' => $key])->save();

                return;
            }
        }
    }

    protected function markGoLive(ServiceProvisioning $case, Model $actor): void
    {
        $case->forceFill([
            'status' => ProvisioningStatus::GoLive,
            'current_step_key' => 'go_live',
            'completed_at' => now(),
        ])->save();

        $subscription = $case->subscription()->first();
        if ($subscription) {
            $this->lifecycleLogs->setOperationalStatus(
                $subscription,
                ServiceOperationalStatus::Up,
                $actor instanceof User ? $actor : User::query()->where('is_management', true)->first() ?? User::query()->first(),
                'Auto-set on provisioning Go Live ('.$case->public_id.')',
            );
            $this->lifecycleLogs->record(
                $subscription,
                'go_live',
                $actor,
                null,
                null,
                null,
                'Provisioning Go Live '.$case->public_id,
                ['provisioning_public_id' => $case->public_id],
            );
        }

        $this->notifications->notifyGoLive($case->fresh(['company', 'service', 'subscription']));
    }

    protected function seedSteps(ServiceProvisioning $case): void
    {
        foreach ($this->registry->steps($case->workflow_key) as $def) {
            ServiceProvisioningStep::query()->create([
                'service_provisioning_id' => $case->id,
                'step_key' => $def['key'],
                'label' => $def['label'],
                'seq' => $def['seq'],
                'parallel_group' => $def['parallel_group'] ?? null,
                'from_department' => $def['from'],
                'to_department' => $def['to'],
                'status' => ($def['key'] === 'partner_resource_request')
                    ? ProvisioningStepStatus::Waiting
                    : ProvisioningStepStatus::Pending,
                'created_by_type' => $case->created_by_type,
                'created_by_id' => $case->created_by_id,
            ]);
        }

        $case->forceFill(['current_step_key' => 'partner_resource_request'])->save();
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validateAndBuildPayload(ServiceProvisioning $case, array $def, array $input): array
    {
        $payload = [];
        foreach ($def['form_fields'] ?? [] as $field) {
            $name = $field['name'];
            $value = $input[$name] ?? null;

            if (($field['type'] ?? '') === 'file') {
                continue;
            }

            if (! empty($field['required']) && ($value === null || $value === '' || $value === [])) {
                throw ValidationException::withMessages([
                    $name => ($field['label'] ?? $name).' is required.',
                ]);
            }

            if ($value !== null && $value !== '') {
                if (($field['type'] ?? '') === 'select' && is_array($field['options'] ?? null)) {
                    $allowed = array_map('strval', array_keys($field['options']));
                    if (! in_array((string) $value, $allowed, true)) {
                        throw ValidationException::withMessages([
                            $name => 'Select a valid '.strtolower((string) ($field['label'] ?? $name)).'.',
                        ]);
                    }
                }
                $payload[$name] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contextSnapshot(Subscription $subscription, array $extra = []): array
    {
        $company = $subscription->company;
        $service = $subscription->service;
        $contact = $subscription->contact;

        return array_merge([
            'company_name' => $company?->legal_name ?: $company?->name,
            'customer_name' => $company?->legal_name ?: $company?->name,
            'contact_number' => $company?->claimPhone() ?: $contact?->phone_number,
            'contact_email' => $company?->email ?: $contact?->email,
            'service_type' => $service?->type ?: $service?->name,
            'service_name' => $service?->name,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    protected function tableSnapshot(ServiceProvisioning $case): array
    {
        $shared = $case->shared_payload ?? [];
        unset($shared['credentials'], $shared['smsc_password'], $shared['smsc_username']);

        return $shared;
    }

    /**
     * @param  array<string, mixed>  $stepDef
     */
    protected function assertSecureAttachment(UploadedFile $file, string $label, array $stepDef = []): void
    {
        $mimes = $stepDef['attachment_accepted_mimes']
            ?? config('provisioning_workflows.attachment_accepted_mimes', 'pdf,doc,docx,png,jpg,jpeg');
        $maxKb = (int) ($stepDef['attachment_max_kb']
            ?? config('provisioning_workflows.attachment_max_kb', 10240));

        SecureUploadedFile::assert($file, 'attachment', $label, $mimes, $maxKb);
    }

    protected function storeAttachment(ServiceProvisioningStep $step, Model $actor, UploadedFile $file): void
    {
        $path = $file->store('provisioning/'.$step->service_provisioning_id.'/'.$step->id, 'local');

        ServiceProvisioningMedia::query()->create([
            'service_provisioning_step_id' => $step->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => SecureUploadedFile::safeOriginalName($file),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_type' => $actor::class,
            'uploaded_by_id' => $actor->getKey(),
        ]);
    }

    protected function resolveWorkflow(Subscription $subscription): ?ProvisioningWorkflow
    {
        $subscription->loadMissing('service');
        $raw = $subscription->service?->provisioning_workflow
            ?? $subscription->service?->type;

        if ($raw instanceof ProvisioningWorkflow) {
            return $raw;
        }

        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        if ($value === ProvisioningWorkflow::PremiumFull->value || $value === 'premium_full') {
            return ProvisioningWorkflow::PremiumFull;
        }

        // Heuristic: premium / api / mt / ussd premium catalog names
        $name = strtolower((string) ($subscription->service?->name ?? ''));
        $type = strtolower((string) ($subscription->service?->type ?? ''));
        $blob = $name.' '.$type;
        if (str_contains($blob, 'premium') || str_contains($blob, 'payment api')
            || preg_match('/\b(api|mt|ussd)\b/', $blob)) {
            return ProvisioningWorkflow::PremiumFull;
        }

        return null;
    }

    protected function assertActorCanAct(ServiceProvisioning $case, ServiceProvisioningStep $step, Model $actor): void
    {
        // Actor is the FROM department (handoff submitter).
        $from = $step->from_department?->value ?? (string) $step->from_department;

        if ($actor instanceof Contact) {
            if ($from !== 'partner') {
                throw ValidationException::withMessages(['step' => 'This step is not for the partner.']);
            }

            return;
        }

        if ($actor instanceof User) {
            $ability = ProvisioningPermissions::abilityForStep((string) $step->step_key);
            if ($ability === null || ! Gate::forUser($actor)->allows($ability, $case)) {
                throw ValidationException::withMessages([
                    'step' => 'You are not permitted to run this service activation action. Ask an admin to grant it in Roles (Shield).',
                ]);
            }

            if ($actor->canBypassServiceProvisioningScope()) {
                return;
            }

            if (! $actor->canActAsProvisioningDepartment($from)) {
                throw ValidationException::withMessages([
                    'step' => 'Your role cannot act on this section step.',
                ]);
            }

            // Department-attached users may act on their sections for any in-scope case.
            if (in_array($from, $actor->provisioningDepartments(), true)) {
                return;
            }

            // AM without department attachment: only cases assigned to them.
            if ($from === ProvisioningDepartment::VasSales->value
                && $actor->hasRole(User::ROLE_ACCOUNT_MANAGER)
                && $actor->ownsServiceProvisioningCase($case)) {
                return;
            }

            throw ValidationException::withMessages([
                'step' => 'You are not assigned to this service activation case.',
            ]);
        }

        throw ValidationException::withMessages(['step' => 'Unauthorized actor.']);
    }
}
