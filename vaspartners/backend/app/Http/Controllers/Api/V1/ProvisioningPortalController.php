<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProvisioningStepStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\Subscription;
use App\Services\Provisioning\ProvisioningWorkflowRegistry;
use App\Services\Provisioning\ServiceProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProvisioningPortalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contact = $this->contact($request);
        $companyId = $contact->company_id;

        $rows = ServiceProvisioning::query()
            ->with(['service:id,name,slug', 'subscription:id,public_id,contract_signed_at,status'])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (ServiceProvisioning $p) => $this->summarize($p, forPartner: true)),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $case = $this->findForContact($request, $publicId);

        return response()->json(['data' => $this->detail($case)]);
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

        return response()->json(['data' => $this->detail($case)], 201);
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

        return response()->json(['data' => $this->detail($case)]);
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
    protected function detail(ServiceProvisioning $p): array
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

        return array_merge($this->summarize($p, true), [
            'company_name' => $p->company?->name,
            'can_act_step' => $canAct,
            'can_act_label' => $waiting?->label,
            'can_act_fields' => $formFields,
            'requires_attachment' => $requiresAttachment,
            'attachment_label' => $attachmentLabel,
            'attachment_accepted_mimes' => $acceptedMimes,
            'attachment_max_kb' => $maxKb,
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
