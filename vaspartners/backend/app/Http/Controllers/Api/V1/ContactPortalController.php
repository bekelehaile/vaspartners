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

class ContactPortalController extends Controller
{
    public function services(Request $request)
    {
        $groupId = $request->integer('group_id') ?: null;

        $services = Service::query()
            ->with([
                'category:id,name,slug,key',
                'categories' => fn ($q) => $q
                    ->operationalGroups()
                    ->select(['categories.id', 'categories.name', 'categories.slug', 'categories.key', 'categories.sort_order']),
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
            ->when($groupId, function ($q) use ($groupId) {
                $q->where(function ($inner) use ($groupId) {
                    $inner->where('category_id', $groupId)
                        ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $groupId));
                });
            })
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'category_id', 'name', 'slug', 'description', 'type', 'is_subscription_based', 'renewal_interval', 'renewal_lead_days', 'sort_order']);

        return response()->json(['data' => $services]);
    }

    public function groups()
    {
        $groups = \App\Models\Category::query()
            ->operationalGroups()
            ->get(['id', 'key', 'name', 'slug', 'sort_order']);

        return response()->json(['data' => $groups]);
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

    public function tickets(Request $request, CompanyMembershipService $membership)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,completed,closed,rejected'],
            'search' => ['nullable', 'string', 'max:120'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var \App\Models\Contact $contact */
        $contact = $request->user();

        $query = Ticket::query()
            ->with([
                'service:id,name',
                'requisition:id,name',
                'contact:id,public_id,name',
            ]);

        if ($contact->current_company_id && $contact->hasActiveCompanyMembership()) {
            $companyId = (int) $contact->current_company_id;
            $companyContactIds = $membership->companyContactIds($companyId);
            $query->where(function ($q) use ($companyContactIds, $companyId) {
                $q->whereIn('contact_id', $companyContactIds)
                    ->orWhereHas('subscription', fn ($sq) => $sq->where('company_id', $companyId));
            });
        } else {
            $query->where('contact_id', $contact->id);
        }

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
                    ->orWhereHas('requisition', fn ($rq) => $rq->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('contact', fn ($cq) => $cq->where('name', 'ilike', "%{$search}%"));
            });
        }

        $tickets = $query
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json($tickets);
    }

    public function showTicket(Request $request, Ticket $ticket, TicketCommentService $comments, CompanyMembershipService $membership)
    {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);

        $ticket->load(['service', 'requisition', 'subscription', 'documents.documentType', 'contact:id,public_id,name']);

        $payload = $ticket->toArray();
        $thread = $comments->paginateThread($ticket, $request->user(), null, null, 40);
        $payload['messages'] = $thread['data'];
        $payload['messages_meta'] = $thread['meta'];
        $payload['chat_locked'] = $ticket->status->locksContactChat();
        $payload['chat_attachment_max_kb'] = $comments->maxAttachmentKb();
        $payload['documents_locked'] = $ticket->status->locksContactDocuments();
        $payload['contact_can_edit'] = $ticket->status->allowsContactEdits();
        $payload['documents'] = collect($payload['documents'] ?? [])->map(function (array $doc) use ($ticket) {
            $doc['download_url'] = url("/api/v1/tickets/{$ticket->tt_number}/documents/{$doc['id']}/download");

            return $doc;
        })->values()->all();

        return response()->json(['data' => $payload]);
    }

    public function ticketMessages(Request $request, Ticket $ticket, TicketCommentService $comments, CompanyMembershipService $membership)
    {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);

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
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'woreda_id' => ['nullable', 'exists:woredas,id'],
            'building' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:1'],
        ]);

        $service = Service::query()->with('categories')->findOrFail($data['service_id']);
        $groupIds = $service->categories
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($groupIds->isEmpty() && $service->category_id) {
            $groupIds = collect([(int) $service->category_id]);
        }

        $requestedGroupId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        if ($requestedGroupId) {
            if ($groupIds->isNotEmpty() && ! $groupIds->contains($requestedGroupId)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'category_id' => 'Selected group is not enabled for this service.',
                ]);
            }
            $data['category_id'] = $requestedGroupId;
        } elseif ($groupIds->count() === 1) {
            $data['category_id'] = $groupIds->first();
        } elseif ($groupIds->count() > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'category_id' => 'Select a group for this request (this service belongs to more than one group).',
            ]);
        } else {
            $data['category_id'] = $service->category_id;
        }

        $ticket = $workflow->createTicket($request->user(), $data);

        return response()->json(['data' => $ticket], 201);
    }

    public function subscriptions(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();

        try {
            $membership->assertCanAccessCompany($contact);
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

        $companyId = (int) $contact->current_company_id;

        $rows = Subscription::query()
            ->with(['service:id,name,slug,renewal_interval'])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(100);

        $companyContactIds = $membership->companyContactIds($companyId);

        $pendingNewServiceIds = Ticket::query()
            ->whereIn('contact_id', $companyContactIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('requisition', fn ($q) => $q->where('creates_subscription', true))
            ->pluck('service_id')
            ->unique()
            ->values();

        $pendingRequests = Ticket::query()
            ->whereIn('contact_id', $companyContactIds)
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

    public function uploadDocument(Request $request, Ticket $ticket, TicketDocumentService $documents, CompanyMembershipService $membership)
    {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);

        $data = $request->validate([
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'file' => ['required', 'file'],
        ]);

        // Resolve admin rules first, then re-validate the file strictly against them.
        $documentType = $documents->resolveAllowedDocumentType($ticket, (int) $data['document_type_id']);
        $documents->assertFileMatchesDocumentType($data['file'], $documentType);

        $doc = $documents->storeForContact(
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
        CompanyMembershipService $membership,
    ) {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);

        $documents->deleteForContact($ticket, $document, $request->user());

        return response()->json(['message' => 'Document removed.']);
    }

    public function downloadDocument(
        Request $request,
        Ticket $ticket,
        TicketDocument $document,
        CompanyMembershipService $membership,
    ): StreamedResponse {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);
        abort_unless((int) $document->ticket_id === (int) $ticket->id, 404);

        $disk = $document->disk ?: 'public';
        abort_unless(
            filled($document->path) && Storage::disk($disk)->exists($document->path),
            404,
        );

        return Storage::disk($disk)->download(
            $document->path,
            $document->original_name ?: basename((string) $document->path),
        );
    }

    public function comment(Request $request, Ticket $ticket, TicketCommentService $comments, PartnerNotificationService $notifications, CompanyMembershipService $membership)
    {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);

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
        CompanyMembershipService $membership,
    ): StreamedResponse {
        $membership->assertCanAccessCompanyTicket($request->user(), $ticket);
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
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();
        if ($contact->current_company_id && ! $contact->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:255'],
            'company_tin' => ['required', 'string', 'max:32'],
            'company_address' => ['required', 'string', 'min:5', 'max:2000'],
            'create_new' => ['sometimes', 'boolean'],
        ]);

        $createNew = (bool) ($data['create_new'] ?? false);
        unset($data['create_new']);

        $fresh = ($createNew || ! $contact->current_company_id)
            ? $membership->createCompanyForContact($contact, $data)
            : $membership->updateOwnCompany($contact, $data);

        return response()->json(['data' => $membership->serializeContact($fresh)]);
    }

    public function submitCompanyTin(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();
        if ($contact->current_company_id && ! $contact->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'company_tin' => ['required', 'string', 'max:32'],
        ]);

        $fresh = $membership->submitCompanyTin($contact, $data['company_tin']);

        return response()->json([
            'message' => 'TIN submitted. Ethio telecom will validate it before you can submit service requests.',
            'data' => $membership->serializeContact($fresh),
        ]);
    }

    public function lookupCompany(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'tin' => ['required', 'string', 'max:32'],
        ]);

        $company = $membership->lookupByIdentity($data['tin']);
        if (! $company) {
            return response()->json(['message' => 'No approved company found for this TIN.', 'data' => null], 404);
        }

        return response()->json([
            'data' => [
                'public_id' => $company->public_id,
                'name' => $company->name,
                'tin' => $company->tin,
            ],
        ]);
    }

    public function requestAttachCompany(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'company_tin' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $change = $membership->requestAttach(
            $request->user(),
            $data['company_tin'],
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $membership->serializeContact($request->user()->fresh()),
            'request' => [
                'public_id' => $change->public_id,
                'type' => $change->type->value,
                'status' => $change->status->value,
            ],
        ], 201);
    }

    public function membershipRequests(Request $request, CompanyMembershipService $membership)
    {
        // Backward-compatible: owners still get pending attach list for current company.
        // Prefer GET profile/company/requests for the shared inbox.
        $rows = $membership->pendingMembershipRequestsForOwner($request->user())
            ->map(fn ($change) => $membership->serializeRequestCard($change, $request->user(), 'to_review'))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function companyRequestsInbox(Request $request, CompanyMembershipService $membership)
    {
        return response()->json([
            'data' => $membership->companyRequestsInbox($request->user()),
        ]);
    }

    public function cancelCompanyRequest(
        Request $request,
        string $changeRequest,
        CompanyMembershipService $membership,
    ) {
        $record = \App\Models\CompanyChangeRequest::query()
            ->where('public_id', $changeRequest)
            ->firstOrFail();

        $membership->cancelOwnRequest($request->user(), $record);

        return response()->json([
            'data' => $membership->serializeContact($request->user()->fresh()),
        ]);
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
            'data' => $membership->serializeContact($request->user()->fresh()),
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
            'data' => $membership->serializeContact($request->user()->fresh()),
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

        return response()->json(['data' => $membership->serializeContact($fresh)]);
    }

    public function requestDetachCompany(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();
        if (! $contact->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fresh = $membership->leaveCompany($contact, $data['note'] ?? null);

        return response()->json([
            'data' => $membership->serializeContact($fresh),
            'message' => 'You have left the company.',
        ]);
    }

    public function companyMembers(Request $request, CompanyMembershipService $membership)
    {
        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'permission_catalog' => \App\Enums\CompanyMemberPermission::catalog(),
        ]);
    }

    public function createCompanyMember(Request $request, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $result = $membership->createMemberByOwner($request->user(), $data);
        $awaiting = $result['member']['awaiting_fayda'] ?? false;
        $linkedExisting = $result['linked_existing'] ?? false;

        $message = match (true) {
            $linkedExisting && $awaiting => 'Existing partner linked to this company. They still need to sign in with Fayda (access applies only if enabled).',
            $linkedExisting => 'Existing partner linked to this company as an additional membership. They can switch companies in the portal.',
            $awaiting => 'Member added. They will sync when they sign in with Fayda using this phone number (only if access stays enabled).',
            default => 'Member added to this company.',
        };

        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'member' => $result['member'],
            'linked_existing' => $linkedExisting,
            'message' => $message,
        ], 201);
    }

    public function enableCompanyMember(Request $request, string $member, CompanyMembershipService $membership)
    {
        $target = $membership->findCurrentCompanyMemberByPublicId($request->user(), $member);
        $membership->setMembershipActiveByOwner($request->user(), $target, true);

        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'message' => 'Member access enabled.',
        ]);
    }

    public function disableCompanyMember(Request $request, string $member, CompanyMembershipService $membership)
    {
        $target = $membership->findCurrentCompanyMemberByPublicId($request->user(), $member);
        $membership->setMembershipActiveByOwner($request->user(), $target, false);

        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'message' => 'Member access disabled.',
        ]);
    }

    public function updateCompanyMemberPermissions(Request $request, string $member, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:64'],
        ]);

        $target = $membership->findCurrentCompanyMemberByPublicId($request->user(), $member);
        $membership->updateMemberPermissionsByOwner(
            $request->user(),
            $target,
            $data['permissions'],
        );

        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'message' => 'Member permissions updated.',
        ]);
    }

    public function updateCompanyMemberPhone(Request $request, string $member, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
        ]);

        $target = $membership->findCurrentCompanyMemberByPublicId($request->user(), $member);
        $membership->updateMemberPhoneByOwner(
            $request->user(),
            $target,
            $data['phone_number'],
        );

        return response()->json([
            'data' => $membership->listCurrentCompanyMembers($request->user()),
            'message' => 'Member phone updated.',
        ]);
    }

    public function requestTransferOwnership(Request $request, CompanyMembershipService $membership)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();
        if (! $contact->hasActiveCompanyMembership()) {
            return response()->json([
                'message' => 'Your membership for this company is disabled. Contact an administrator.',
            ], 403);
        }

        $data = $request->validate([
            'target_contact' => ['required', 'string', 'max:64'],
            'letter' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $change = $membership->requestOwnershipTransfer(
            $contact,
            $data['target_contact'],
            $request->file('letter'),
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $membership->serializeContact($contact->fresh()),
            'request' => [
                'public_id' => $change->public_id,
                'type' => $change->type->value,
                'status' => $change->status->value,
            ],
            'message' => 'Ownership transfer submitted. An administrator must approve it.',
        ], 201);
    }
}
