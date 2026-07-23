<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Service;
use App\Models\ServiceRequisitionDocument;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketDocument;
use App\Services\PartnerNotificationService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\Request;

class ClientPortalController extends Controller
{
    public function services()
    {
        $services = Service::query()
            ->with([
                'category:id,name,slug',
                'requisitions:id,name,slug,code,creates_subscription,requires_active_subscription,renews_subscription,terminates_subscription,sort_order',
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
            ->with('documentType')
            ->where($data)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function faqs()
    {
        return response()->json([
            'data' => Faq::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function tickets(Request $request)
    {
        $tickets = Ticket::query()
            ->with(['service:id,name', 'requisition:id,name', 'statusHistories' => fn ($q) => $q->latest('created_at')->limit(5)])
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate(20);

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
            'description' => ['nullable', 'string'],
        ]);

        $service = Service::query()->findOrFail($data['service_id']);
        $data['category_id'] = $service->category_id;

        $ticket = $workflow->createTicket($request->user(), $data);

        return response()->json(['data' => $ticket], 201);
    }

    public function subscriptions(Request $request)
    {
        $rows = Subscription::query()
            ->with(['service:id,name,slug,renewal_interval'])
            ->where('customer_id', $request->user()->id)
            ->latest('id')
            ->paginate(20);

        return response()->json($rows);
    }

    public function uploadDocument(Request $request, Ticket $ticket)
    {
        abort_unless($ticket->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'document_type_id' => ['required', 'exists:document_types,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $data['file'];
        $path = $file->store('tickets/'.$ticket->public_id, 'local');

        $doc = TicketDocument::query()->create([
            'ticket_id' => $ticket->id,
            'document_type_id' => $data['document_type_id'],
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_customer_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $doc], 201);
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
