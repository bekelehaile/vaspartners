<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();

        $notifications = $customer->notifications()
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->transform($n));

        return response()->json([
            'data' => $notifications,
            'unread_count' => $customer->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();

        $notification = $customer->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['data' => $this->transform($notification->fresh())]);
    }

    public function markAllRead(Request $request)
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        $customer->unreadNotifications->markAsRead();

        return response()->json([
            'data' => true,
            'unread_count' => 0,
        ]);
    }

    protected function transform(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? 'Update',
            'body' => $data['body'] ?? '',
            'template' => $data['template'] ?? null,
            'ticket_public_id' => $data['ticket_public_id'] ?? null,
            'tt_number' => $data['tt_number'] ?? null,
            'url' => $data['url'] ?? '/portal',
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
