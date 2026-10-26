<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
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
            ->with(['service:id,name,slug', 'subscription:id,public_id,contract_signed_at,status'])
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

        $openSubIds = ServiceProvisioning::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                ProvisioningStatus::Draft->value,
                ProvisioningStatus::InProgress->value,
                ProvisioningStatus::Blocked->value,
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
            ->when($openSubIds !== [], fn ($q) => $q->whereNotIn('id', $openSubIds))
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
            'remark' => ['nullable', 'string', 'max:2000'],
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

        $data = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file'],
            'short_code' => ['nullable', 'string', 'max:64'],
            'data_sync_url' => ['nullable', 'url', 'max:500'],
        ]);

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

        $notifications->provisioningMessagePosted($case, $contact, $message);

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
        return [
            'public_id' => $p->public_id,
            'status' => $p->status?->value,
            'status_label' => $p->status?->label(),
            'workflow_key' => $p->workflow_key?->value ?? $p->workflow_key,
            'current_step_key' => $p->current_step_key,
            'service' => $p->service?->only(['id', 'name', 'slug']),
            'subscription_public_id' => $p->subscription?->public_id,
            'overall_days' => $p->overallElapsedDays(),
            'started_at' => optional($p->started_at)?->toIso8601String(),
            'completed_at' => optional($p->completed_at)?->toIso8601String(),
        ];
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
                ->map(fn (array $f) => [
                    'name' => $f['name'],
                    'label' => $f['label'] ?? $f['name'],
                    'type' => $f['type'] ?? 'text',
                    'required' => (bool) ($f['required'] ?? false),
                ])
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
            'can_act_label' => $waiting?->label,
            'can_act_fields' => $formFields,
            'requires_attachment' => $requiresAttachment,
            'attachment_label' => $attachmentLabel,
            'attachment_accepted_mimes' => $acceptedMimes,
            'attachment_max_kb' => $maxKb,
            'chat_locked' => $messageService->chatLocked($p),
            'chat_attachment_max_kb' => $messageService->maxAttachmentKb(),
            'messages' => $thread['data'],
            'messages_meta' => $thread['meta'],
            'credentials' => $credentials,
            'follow_up' => $p->steps->map(fn ($s) => [
                'step_key' => $s->step_key,
                'what' => $s->label,
                'where' => $s->whereLabel(),
                'status' => $s->status?->value,
                'status_label' => $s->status?->label(),
                'created_at' => optional($s->created_at)?->toIso8601String(),
                'created_by' => null,
                'days_in_step' => $s->daysInStep(),
                'remark' => $s->remark,
            ])->values(),
        ]);
    }
}
