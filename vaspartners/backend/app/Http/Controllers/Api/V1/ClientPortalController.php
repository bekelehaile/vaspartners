<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceRequisitionDocument;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketDocument;
use App\Services\CompanyMembershipService;
use App\Services\PartnerNotificationService;
use App\Services\TicketCommentService;
use App\Services\TicketDocumentService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortalController extends Controller
{
    public function services()
    {
        $services = Service::query()
            ->with([
                'category:id,name,slug',
                'requisitions' => fn ($q) => $q
                    ->where('requisitions.is_active', true)
                    ->orderBy('requisitions.sort_order')
                    ->select([
                        'requisitions.id',
                        'requisitions.name',
                        'requisitions.slug',
                        'requisitions.code',
                        'requisitions.creates_subscription',
                        'requisitions.requires_active_subscription',
                        'requisitions.renews_subscription',
                        'requisitions.terminates_subscription',
                        'requisitions.sort_order',
                    ]),
            ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'category_id', 'name', 'slug', 'description', 'type', 'is_subscription_based', 'renewal_interval', 'renewal_lead_days', 'sort_order']);

        return response()->json(['data' => $services]);
    }

    public function documentRequirements(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'requisition_id' => ['required', 'integer'],
        ]);

        $rows = ServiceRequisitionDocument::query()
            ->with(['documentType' => fn ($q) => $q->where('is_active', true)])
            ->where($data)
            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceRequisitionDocument $row) => [
                'id' => $row->id,
                'is_required' => (bool) $row->is_required,
                'sort_order' => (int) $row->sort_order,
                'document_type' => [
                    'id' => $row->documentType->id,
                    'name' => $row->documentType->name,
                    'code' => $row->documentType->code,
                    'accepted_mimes' => $row->documentType->accepted_mimes,
                    'max_size_kb' => (int) $row->documentType->max_size_kb,
                    'description' => $row->documentType->description,
                ],
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function tickets(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,completed,closed,rejected'],
            'search' => ['nullable', 'string', 'max:120'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Ticket::query()
            ->with([
                'service:id,name',
                'requisition:id,name',
            ])
            ->where('customer_id', $request->user()->id);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('tt_number', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('building', 'ilike', "%{$search}%")
                    ->orWhereHas('service', fn ($sq) => $sq->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('requisition', fn ($rq) => $rq->where('name', 'ilike', "%{$search}%"));
            });
        }

        $tickets = $query
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json($tickets);
    }

    public function showTicket(Request $request, Ticket $ticket, TicketCommentService $comments)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $ticket->load(['service', 'requisition', 'subscription', 'documents.documentType']);

        $payload = $ticket->toArray();
        $thread = $comments->paginateThread($ticket, $request->user(), null, null, 40);
        $payload['messages'] = $thread['data'];
        $payload['messages_meta'] = $thread['meta'];
        $payload['chat_locked'] = $ticket->status->locksCustomerChat();
        $payload['chat_attachment_max_kb'] = $comments->maxAttachmentKb();
        $payload['documents_locked'] = $ticket->status->locksCustomerDocuments();
        $payload['customer_can_edit'] = $ticket->status->allowsCustomerEdits();

        return response()->json(['data' => $payload]);
    }

    public function ticketMessages(Request $request, Ticket $ticket, TicketCommentService $comments)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = $comments->paginateThread(
            $ticket,
            $request->user(),
            isset($data['before_id']) ? (int) $data['before_id'] : null,
            isset($data['after_id']) ? (int) $data['after_id'] : null,
            (int) ($data['limit'] ?? 30),
        );

        return response()->json($page);
    }

    public function storeTicket(Request $request, TicketWorkflowService $workflow)
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'requisition_id' => ['required', 'exists:requisitions,id'],
            'subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'woreda_id' => ['nullable', 'exists:woredas,id'],
            'building' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:1'],
        ]);

        $service = Service::query()->findOrFail($data['service_id']);
        $data['category_id'] = $service->category_id;

        $ticket = $workflow->createTicket($request->user(), $data);

        return response()->json(['data' => $ticket], 201);
    }

    public function subscriptions(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();

        try {
            $membership->assertCanAccessCompany($customer);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?: 'Complete and get your company TIN approved before viewing subscriptions.';

            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 100,
                'total' => 0,
                'pending_new_service_ids' => [],
                'pending_requests' => [],
                'message' => $message,
            ]);
        }

        $companyId = (int) $customer->current_company_id;

        $rows = Subscription::query()
            ->with(['service:id,name,slug,renewal_interval'])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(100);

        $companyCustomerIds = \App\Models\CompanyMembership::query()
            ->where('company_id', $companyId)
            ->pluck('customer_id');

        $pendingNewServiceIds = Ticket::query()
            ->whereIn('customer_id', $companyCustomerIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('requisition', fn ($q) => $q->where('creates_subscription', true))
            ->pluck('service_id')
            ->unique()
            ->values();

        $pendingRequests = Ticket::query()
            ->whereIn('customer_id', $companyCustomerIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->get(['service_id', 'requisition_id', 'tt_number', 'public_id', 'status'])
            ->map(fn (Ticket $t) => [
                'service_id' => (int) $t->service_id,
                'requisition_id' => (int) $t->requisition_id,
                'tt_number' => $t->tt_number,
                'public_id' => $t->public_id,
                'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
            ])
            ->values();

        return response()->json([
            'data' => $rows->items(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'pending_new_service_ids' => $pendingNewServiceIds,
            'pending_requests' => $pendingRequests,
        ]);
    }

    public function uploadDocument(Request $request, Ticket $ticket, TicketDocumentService $documents)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'file' => ['required', 'file'],
        ]);

        // Resolve admin rules first, then re-validate the file strictly against them.
        $documentType = $documents->resolveAllowedDocumentType($ticket, (int) $data['document_type_id']);
        $documents->assertFileMatchesDocumentType($data['file'], $documentType);

        $doc = $documents->storeForCustomer(
            $ticket,
            $request->user(),
            (int) $data['document_type_id'],
            $data['file'],
        );

        return response()->json(['data' => $doc], 201);
    }

    public function deleteDocument(
        Request $request,
        Ticket $ticket,
        TicketDocument $document,
        TicketDocumentService $documents,
    ) {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $documents->deleteForCustomer($ticket, $document, $request->user());

        return response()->json(['message' => 'Document removed.']);
    }

    public function comment(Request $request, Ticket $ticket, TicketCommentService $comments, PartnerNotificationService $notifications)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file'],
        ]);

        $comment = $comments->post(
            $ticket,
            $request->user(),
            $data['body'] ?? null,
            $request->file('attachment'),
        );

        $notifications->ticketMessagePosted($ticket, $request->user(), $comment);

        return response()->json([
            'data' => $comments->serializeComment($ticket, $comment->load('author'), $request->user()),
        ], 201);
    }

    public function downloadCommentAttachment(
        Request $request,
        Ticket $ticket,
        TicketComment $comment,
        TicketCommentService $comments,
    ): StreamedResponse {
        abort_unless($ticket->customer_id === $request->user()->id, 404);
        abort_unless((int) $comment->ticket_id === (int) $ticket->id, 404);
        abort_unless($comment->is_public, 404);
        abort_unless($comments->attachmentExists($comment), 404);

        $disk = $comment->attachment_disk ?: 'local';

        return Storage::disk($disk)->download(
            $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment.pdf',
        );
    }

    public function completeCompanyProfile(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        if ($customer->current_company_id && ! $customer->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:255'],
            'company_tin' => ['required', 'string', 'min:5', 'max:64'],
            'company_license_number' => ['required', 'string', 'min:3', 'max:64'],
            'company_phone' => ['required', 'string', 'min:9', 'max:32'],
            'company_email' => ['required', 'email', 'max:255'],
            'company_address' => ['required', 'string', 'min:5', 'max:2000'],
            'create_new' => ['sometimes', 'boolean'],
        ]);

        $createNew = (bool) ($data['create_new'] ?? false);
        unset($data['create_new']);

        $fresh = ($createNew || ! $customer->current_company_id)
            ? $membership->createCompanyForCustomer($customer, $data)
            : $membership->updateOwnCompany($customer, $data);

        return response()->json(['data' => $membership->serializeCustomer($fresh)]);
    }

    public function lookupCompany(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'tin' => ['required', 'string', 'min:5', 'max:64'],
            'license_number' => ['required', 'string', 'min:3', 'max:64'],
        ]);

        $company = $membership->lookupByIdentity($data['tin'], $data['license_number']);
        if (! $company) {
            return response()->json(['message' => 'No company found for this TIN and license number.', 'data' => null], 404);
        }

        return response()->json([
            'data' => [
                'public_id' => $company->public_id,
                'name' => $company->name,
                'tin' => $company->tin,
                'license_number' => $company->license_number,
            ],
        ]);
    }

    public function requestAttachCompany(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'company_tin' => ['required', 'string', 'min:5', 'max:64'],
            'company_license_number' => ['required', 'string', 'min:3', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $change = $membership->requestAttach(
            $request->user(),
            $data['company_tin'],
            $data['company_license_number'],
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $membership->serializeCustomer($request->user()->fresh()),
            'request' => [
                'public_id' => $change->public_id,
                'type' => $change->type->value,
                'status' => $change->status->value,
            ],
        ], 201);
    }

    public function membershipRequests(Request $request, CompanyMembershipService $membership)
    {
        $rows = $membership->pendingMembershipRequestsForOwner($request->user())
            ->map(fn ($change) => [
                'public_id' => $change->public_id,
                'type' => $change->type->value,
                'status' => $change->status->value,
                'customer_note' => $change->customer_note,
                'created_at' => optional($change->created_at)?->toIso8601String(),
                'applicant' => [
                    'public_id' => $change->customer?->public_id,
                    'name' => $change->customer?->name,
                    'phone_number' => $change->customer?->phone_number,
                    'email' => $change->customer?->email,
                ],
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function approveMembershipRequest(
        Request $request,
        string $changeRequest,
        CompanyMembershipService $membership,
    ) {
        $record = \App\Models\CompanyChangeRequest::query()
            ->where('public_id', $changeRequest)
            ->firstOrFail();

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fresh = $membership->approve($record, $request->user(), $data['note'] ?? null);

        return response()->json([
            'data' => $membership->serializeCustomer($request->user()->fresh()),
            'request' => [
                'public_id' => $fresh->public_id,
                'status' => $fresh->status->value,
            ],
        ]);
    }

    public function rejectMembershipRequest(
        Request $request,
        string $changeRequest,
        CompanyMembershipService $membership,
    ) {
        $record = \App\Models\CompanyChangeRequest::query()
            ->where('public_id', $changeRequest)
            ->firstOrFail();

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fresh = $membership->reject($record, $request->user(), $data['note'] ?? null);

        return response()->json([
            'data' => $membership->serializeCustomer($request->user()->fresh()),
            'request' => [
                'public_id' => $fresh->public_id,
                'status' => $fresh->status->value,
            ],
        ]);
    }

    public function switchCompany(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'company_public_id' => ['required', 'string', 'max:26'],
        ]);

        $company = \App\Models\Company::query()
            ->where('public_id', $data['company_public_id'])
            ->firstOrFail();

        $fresh = $membership->switchCompany($request->user(), $company);

        return response()->json(['data' => $membership->serializeCustomer($fresh)]);
    }

    public function requestDetachCompany(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        if (! $customer->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fresh = $membership->leaveCompany($customer, $data['note'] ?? null);

        return response()->json([
            'data' => $membership->serializeCustomer($fresh),
            'message' => 'You have left the company.',
        ]);
    }

    public function companyMembers(Request $request, CompanyMembershipService $membership)
    {
        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
        ]);
    }

    public function requestTransferOwnership(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        if (! $customer->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'target_customer' => ['required', 'string', 'max:64'],
            'letter' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $change = $membership->requestOwnershipTransfer(
            $customer,
            $data['target_customer'],
            $request->file('letter'),
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $membership->serializeCustomer($customer->fresh()),
            'request' => [
                'public_id' => $change->public_id,
                'type' => $change->type->value,
                'status' => $change->status->value,
            ],
            'message' => 'Ownership transfer submitted. An administrator must approve it.',
        ], 201);
    }
}
