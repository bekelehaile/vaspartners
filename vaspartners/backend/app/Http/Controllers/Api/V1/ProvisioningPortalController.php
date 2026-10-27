<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use App\Models\Subscription;
use App\Services\PartnerNotificationService;
use App\Services\Provisioning\ProvisioningWorkflowRegistry;
use App\Services\Provisioning\ServiceProvisioningMessageService;
use App\Services\Provisioning\ServiceProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProvisioningPortalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contact = $this->contact($request);
        $companyId = (int) $contact->company_id;

        $rows = ServiceProvisioning::query()
            ->with([
                'service:id,name,slug',
                'subscription:id,public_id,contract_signed_at,status',
                'steps',
            ])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (ServiceProvisioning $p) => $this->summarize($p, forPartner: true)),
            'startable' => $this->startableSubscriptions($companyId),
            'attachment_accepted_mimes' => (string) config(
                'provisioning_workflows.attachment_accepted_mimes',
                'pdf,doc,docx,png,jpg,jpeg'
            ),
            'attachment_max_kb' => (int) config('provisioning_workflows.attachment_max_kb', 10240),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /**
     * Active subscriptions with signed contract + workflow and no open PRV — ready for customer to initiate.
     *
     * @return list<array<string, mixed>>
     */
    protected function startableSubscriptions(int $companyId): array
    {
        if ($companyId < 1) {
            return [];
        }

        // Once a PRV exists (including completed), partner uses View on that case — not a fresh Submit.
        $provisionedSubIds = ServiceProvisioning::query()
            ->where('company_id', $companyId)
            ->whereNotIn('status', [
                ProvisioningStatus::Cancelled->value,
                ProvisioningStatus::Rejected->value,
            ])
            ->pluck('subscription_id')
            ->all();

        return Subscription::query()
            ->with(['service:id,name,slug,provisioning_workflow'])
            ->where('company_id', $companyId)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PendingRenewal->value,
                SubscriptionStatus::Grace->value,
            ])
            ->whereNotNull('contract_signed_at')
            ->whereHas('service', fn ($q) => $q->whereNotNull('provisioning_workflow')->where('provisioning_workflow', '!=', ''))
            ->when($provisionedSubIds !== [], fn ($q) => $q->whereNotIn('id', $provisionedSubIds))
            ->latest('id')
            ->get()
            ->map(fn (Subscription $s) => [
                'public_id' => $s->public_id,
                'status' => $s->status instanceof SubscriptionStatus ? $s->status->value : (string) $s->status,
                'contract_signed_at' => optional($s->contract_signed_at)?->toDateString(),
                'service' => $s->service?->only(['id', 'name', 'slug']),
            ])
            ->values()
            ->all();
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $case = $this->findForContact($request, $publicId);

        return response()->json(['data' => $this->detail($case, $this->contact($request))]);
    }

    public function start(Request $request, ServiceProvisioningService $service): JsonResponse
    {
        $contact = $this->contact($request);
        $data = $request->validate([
            'subscription_public_id' => ['required', 'string'],
            'short_code' => ['required', 'string', 'max:64'],
            'data_sync_url' => ['nullable', 'url', 'max:500'],
            'remark' => ['required', 'string', 'min:1', 'max:2000'],
            // Deep type/size/MIME checks run in ServiceProvisioningService (same as ticket documents).
            'attachment' => ['required', 'file'],
        ]);

        $subscription = Subscription::query()
            ->where('public_id', $data['subscription_public_id'])
            ->where('company_id', $contact->company_id)
            ->firstOrFail();

        try {
            $case = $service->startFromPartner(
                $subscription,
                $contact,
                $data,
                $request->file('attachment'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json(['data' => $this->detail($case, $contact)], 201);
    }

    public function submitStep(
        Request $request,
        string $publicId,
        string $stepKey,
        ServiceProvisioningService $service,
    ): JsonResponse {
        $case = $this->findForContact($request, $publicId);
        $contact = $this->contact($request);

        $rules = [
            'remark' => ['required', 'string', 'min:1', 'max:2000'],
            'attachment' => ['nullable', 'file'],
            'short_code' => ['nullable', 'string', 'max:64'],
            'data_sync_url' => ['nullable', 'url', 'max:500'],
            // Partner config confirmation (Completed / Cancelled).
            'completion_status' => ['nullable', 'string', 'in:completed,cancelled'],
        ];

        // Accept any other simple text form fields declared on the waiting step.
        $def = app(ProvisioningWorkflowRegistry::class)->step($case->workflow_key, $stepKey) ?? [];
        foreach ($def['form_fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || isset($rules[$name]) || ($field['type'] ?? '') === 'file') {
                continue;
            }
            $rules[$name] = ! empty($field['required'])
                ? ['required', 'string', 'max:2000']
                : ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules);

        $case = $service->submitStep(
            $case,
            $stepKey,
            $contact,
            $data,
            $request->file('attachment'),
        );

        return response()->json(['data' => $this->detail($case, $contact)]);
    }

    public function messages(
        Request $request,
        string $publicId,
        ServiceProvisioningMessageService $messages,
    ): JsonResponse {
        $case = $this->findForContact($request, $publicId);
        $contact = $this->contact($request);
        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = $messages->paginateThread(
            $case,
            $contact,
            isset($data['before_id']) ? (int) $data['before_id'] : null,
            isset($data['after_id']) ? (int) $data['after_id'] : null,
            (int) ($data['limit'] ?? 40),
        );

        return response()->json($page);
    }

    public function postMessage(
        Request $request,
        string $publicId,
        ServiceProvisioningMessageService $messages,
        PartnerNotificationService $notifications,
    ): JsonResponse {
        $case = $this->findForContact($request, $publicId);
        $contact = $this->contact($request);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file'],
        ]);

        $message = $messages->post(
            $case,
            $contact,
            $data['body'] ?? null,
            $request->file('attachment'),
        );

        try {
            $notifications->provisioningMessagePosted($case, $contact, $message);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => $messages->serialize($case, $message->load('author')),
        ], 201);
    }

    public function downloadMessageAttachment(
        Request $request,
        string $publicId,
        int $messageId,
        ServiceProvisioningMessageService $messages,
    ): StreamedResponse {
        $case = $this->findForContact($request, $publicId);
        $message = $case->messages()
            ->whereKey($messageId)
            ->where('is_public', true)
            ->firstOrFail();

        abort_unless($messages->attachmentExists($message), 404);

        $disk = $message->attachment_disk ?: 'local';

        return Storage::disk($disk)->download(
            $message->attachment_path,
            $message->attachment_original_name ?: 'attachment.pdf',
        );
    }

    protected function contact(Request $request): Contact
    {
        $user = $request->user();
        if (! $user instanceof Contact) {
            abort(401);
        }

        return $user;
    }

    protected function findForContact(Request $request, string $publicId): ServiceProvisioning
    {
        $contact = $this->contact($request);

        return ServiceProvisioning::query()
            ->with(['steps.media', 'service', 'company', 'subscription'])
            ->where('public_id', $publicId)
            ->where('company_id', $contact->company_id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarize(ServiceProvisioning $p, bool $forPartner = false): array
    {
        $base = [
            'public_id' => $p->public_id,
            'status' => $p->status?->value,
            'status_label' => $p->status?->label(),
            'service' => $p->service?->only(['id', 'name', 'slug']),
            'subscription_public_id' => $p->subscription?->public_id,
            'started_at' => optional($p->started_at)?->toIso8601String(),
            'completed_at' => optional($p->completed_at)?->toIso8601String(),
            'overall_days' => $p->overallElapsedDays(),
        ];

        if ($forPartner) {
            $waiting = $p->relationLoaded('steps')
                ? $p->steps->firstWhere('status', ProvisioningStepStatus::Waiting)
                : null;
            $canAct = $waiting && ($waiting->from_department?->value === 'partner')
                ? $waiting->step_key
                : null;

            return array_merge($base, [
                'can_act_step' => $canAct,
                'can_act_submit_label' => $canAct ? $this->partnerSubmitLabel($waiting) : null,
            ]);
        }

        return array_merge($base, [
            'workflow_key' => $p->workflow_key?->value ?? $p->workflow_key,
            'current_step_key' => $p->current_step_key,
        ]);
    }

    /**
     * Partner-facing action labels (no internal department names like "VAS Sales").
     */
    protected function partnerSubmitLabel(?ServiceProvisioningStep $waiting): string
    {
        return match ($waiting?->step_key) {
            'partner_resource_request' => 'Submit activation request',
            'partner_config_completed' => 'Confirm configuration',
            default => 'Submit',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(ServiceProvisioning $p, ?Contact $viewer = null): array
    {
        $p->loadMissing(['steps', 'service', 'subscription', 'company']);
        $shared = $p->shared_payload ?? [];
        $released = (bool) ($shared['credentials_released_to_partner'] ?? false);
        $credentials = null;
        if ($released && ! empty($shared['credentials'])) {
            $credentials = $shared['credentials'];
        }

        $waiting = $p->steps->firstWhere('status', ProvisioningStepStatus::Waiting);
        $canAct = $waiting && ($waiting->from_department?->value === 'partner')
            ? $waiting->step_key
            : null;

        $formFields = [];
        $requiresAttachment = false;
        $attachmentLabel = 'Attachment';
        if ($canAct) {
            $def = app(ProvisioningWorkflowRegistry::class)->step($p->workflow_key, $canAct) ?? [];
            $formFields = collect($def['form_fields'] ?? [])
                ->map(function (array $f) {
                    $row = [
                        'name' => $f['name'],
                        'label' => $f['label'] ?? $f['name'],
                        'type' => $f['type'] ?? 'text',
                        'required' => (bool) ($f['required'] ?? false),
                    ];
                    if (($f['type'] ?? '') === 'select' && is_array($f['options'] ?? null)) {
                        $row['options'] = collect($f['options'])
                            ->map(fn ($label, $value) => [
                                'value' => (string) $value,
                                'label' => (string) $label,
                            ])
                            ->values()
                            ->all();
                    }

                    return $row;
                })
                ->values()
                ->all();
            $requiresAttachment = (bool) ($def['requires_attachment'] ?? false);
            $attachmentLabel = (string) ($def['attachment_label'] ?? 'Attachment');
        }

        $acceptedMimes = (string) config(
            'provisioning_workflows.attachment_accepted_mimes',
            'pdf,doc,docx,png,jpg,jpeg'
        );
        $maxKb = (int) config('provisioning_workflows.attachment_max_kb', 10240);
        $messageService = app(ServiceProvisioningMessageService::class);
        $thread = $messageService->paginateThread($p, $viewer, null, null, 40);

        return array_merge($this->summarize($p, true), [
            'company_name' => $p->company?->name,
            'can_act_step' => $canAct,
            'can_act_label' => $canAct ? $this->partnerSubmitLabel($waiting) : null,
            'can_act_submit_label' => $canAct ? $this->partnerSubmitLabel($waiting) : null,
            'can_act_fields' => $formFields,
            'requires_attachment' => $requiresAttachment,
            'attachment_label' => $attachmentLabel,
            'attachment_accepted_mimes' => $acceptedMimes,
            'attachment_max_kb' => $maxKb,
            'chat_locked' => ! $messageService->partnerMessagingOpen($p),
            'chat_block_reason' => $messageService->partnerChatBlockReason($p),
            'chat_attachment_max_kb' => $messageService->maxAttachmentKb(),
            'messages' => $thread['data'],
            'messages_meta' => $thread['meta'],
            'credentials' => $credentials,
            'follow_up' => $this->partnerFollowUp($p),
        ]);
    }

    /**
     * Partner-safe progress only — no internal department handoffs, remarks, or queues.
     *
     * @return array{headline: string, awaiting_ethio_telecom: bool, items: list<array<string, mixed>>}
     */
    protected function partnerFollowUp(ServiceProvisioning $p): array
    {
        $partnerLabels = [
            'partner_resource_request' => 'Your activation request',
            'sales_share_credentials' => 'Credentials shared with you',
            'partner_config_completed' => 'Your configuration confirmation',
            'go_live' => 'Go Live',
        ];

        $items = [];
        foreach ($p->steps as $step) {
            $from = $step->from_department?->value ?? (string) $step->from_department;
            $to = $step->to_department?->value ?? (string) $step->to_department;
            $involvesPartner = $from === 'partner' || $to === 'partner';
            if (! $involvesPartner) {
                continue;
            }

            $key = (string) $step->step_key;
            $items[] = [
                'step_key' => $key,
                'what' => $partnerLabels[$key] ?? 'Update for you',
                'status' => $step->status?->value,
                'status_label' => $step->status?->label(),
                'needs_you' => $step->status === ProvisioningStepStatus::Waiting && $from === 'partner',
            ];
        }

        $waiting = $p->steps->firstWhere('status', ProvisioningStepStatus::Waiting);
        $waitingOnPartner = $waiting
            && ($waiting->from_department?->value === 'partner');
        $awaitingEthio = $waiting !== null && ! $waitingOnPartner
            && ! in_array($p->status, [ProvisioningStatus::GoLive, ProvisioningStatus::Cancelled, ProvisioningStatus::Rejected], true);

        $headline = match (true) {
            $p->status === ProvisioningStatus::GoLive => 'Service activation is complete (Go Live).',
            $p->status === ProvisioningStatus::Cancelled => 'This activation was cancelled.',
            $p->status === ProvisioningStatus::Rejected => 'This activation was not approved.',
            $waitingOnPartner => 'Action needed from you — complete the form below, or check messages.',
            $awaitingEthio => 'Ethio telecom is processing your activation. We will notify you when something is ready.',
            default => 'Activation in progress.',
        };

        return [
            'headline' => $headline,
            'awaiting_ethio_telecom' => $awaitingEthio,
            'items' => array_values($items),
        ];
    }
}
