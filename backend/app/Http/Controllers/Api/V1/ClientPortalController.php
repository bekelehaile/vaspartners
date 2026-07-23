<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceRequisitionDocument;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketDocument;
use App\Services\PartnerNotificationService;
use App\Services\TicketDocumentService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\Request;

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
            ->get();

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
                'statusHistories' => fn ($q) => $q->latest('created_at')->limit(5),
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

    public function showTicket(Request $request, Ticket $ticket)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $ticket->load(['service', 'requisition', 'subscription', 'documents.documentType', 'comments', 'statusHistories']);

        return response()->json(['data' => $ticket]);
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

    public function subscriptions(Request $request)
    {
        $customerId = $request->user()->id;

        $rows = Subscription::query()
            ->with(['service:id,name,slug,renewal_interval'])
            ->where('customer_id', $customerId)
            ->latest('id')
            ->paginate(100);

        $pendingNewServiceIds = Ticket::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('requisition', fn ($q) => $q->where('creates_subscription', true))
            ->pluck('service_id')
            ->unique()
            ->values();

        return response()->json([
            'data' => $rows->items(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'pending_new_service_ids' => $pendingNewServiceIds,
        ]);
    }

    public function uploadDocument(Request $request, Ticket $ticket, TicketDocumentService $documents)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'file' => ['required', 'file'],
        ]);

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

    public function comment(Request $request, Ticket $ticket)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = TicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => $request->user()::class,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'is_public' => true,
        ]);

        return response()->json(['data' => $comment], 201);
    }

    public function completeCompanyProfile(Request $request, PartnerNotificationService $notifications)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:255'],
            'company_tin' => ['required', 'string', 'min:5', 'max:64'],
            'company_phone' => ['required', 'string', 'min:9', 'max:32'],
            'company_email' => ['required', 'email', 'max:255'],
            'company_address' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        $wasIncomplete = ! $customer->profile_completed;
        $customer->fill($data);
        $customer->profile_completed_at = now();
        $customer->save();

        $fresh = $customer->fresh();
        if ($wasIncomplete) {
            $notifications->profileCompleted($fresh);
        }

        return response()->json(['data' => $fresh]);
    }
}
