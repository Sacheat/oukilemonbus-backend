<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:100'],
            'priority' => ['sometimes', 'string', 'in:high,normal,low'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = $request->user()
            ->notifications()
            ->when(
                array_key_exists('unread', $validated) && (bool) $validated['unread'],
                fn ($query) => $query->whereNull('read_at')
            )
            ->when(
                isset($validated['category']),
                fn ($query) => $query->where('data->category', $validated['category'])
            )
            ->when(
                isset($validated['priority']),
                fn ($query) => $query->where('data->priority', $validated['priority'])
            )
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 15));

        return InAppNotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();

        if ($notification instanceof DatabaseNotification && $notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json(null, 204);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json(null, 204);
    }

    public function markManyAsRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $request->user()
            ->unreadNotifications()
            ->whereIn('id', $validated['ids'])
            ->update(['read_at' => now()]);

        return response()->json(null, 204);
    }
}
