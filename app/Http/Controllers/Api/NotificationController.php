<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use App\Services\Notifications\NotificationHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gère les notifications in-app de l'utilisateur connecté (table notifications native Laravel).
 * L'endpoint /health est réservé à l'admin et concerne les livraisons async (notification_deliveries),
 * pas les notifications in-app de cet utilisateur.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $this->validateFilters($request);

        $notifications = $this->filteredQuery($request, $validated)
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 15));

        return InAppNotificationResource::collection($notifications);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $this->validateFilters($request, false);
        $query = $this->filteredQuery($request, $validated);

        $total  = (clone $query)->count();
        $unread = (clone $query)->whereNull('read_at')->count();
        $read   = $total - $unread;

        // Les métadonnées (category, priority) sont stockées dans la colonne JSON `data`.
        // JSON_UNQUOTE + JSON_EXTRACT permet de les extraire et grouper directement en SQL.
        $byCategory = (clone $query)
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')), 'unknown') as category")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->groupBy('category')
            ->get()
            ->map(fn ($row): array => [
                'category' => (string) $row->category,
                'total' => (int) $row->total,
                'unread' => (int) $row->unread,
            ])
            ->values();

        $byPriority = (clone $query)
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.priority')), 'normal') as priority")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->groupBy('priority')
            ->get()
            ->map(fn ($row): array => [
                'priority' => (string) $row->priority,
                'total' => (int) $row->total,
                'unread' => (int) $row->unread,
            ])
            ->values();

        return response()->json([
            'data' => [
                'total' => $total,
                'unread' => $unread,
                'read' => $read,
                'by_category' => $byCategory,
                'by_priority' => $byPriority,
            ],
        ]);
    }

    public function markByFilterAsRead(Request $request): JsonResponse
    {
        $validated = $this->validateFilters($request, false);

        $updated = $this->filteredQuery($request, $validated)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'marked_as_read' => $updated,
            ],
        ]);
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

    public function health(NotificationHealthService $healthService): JsonResponse
    {
        $summary = $healthService->summary();
        $healthy = $healthService->isHealthy($summary);

        return response()->json([
            'status' => $healthy ? 'ok' : 'alert',
            'data' => $summary,
        ]);
    }

    private function validateFilters(Request $request, bool $includePagination = true): array
    {
        $rules = [
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:100'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['required', 'string', 'max:100'],
            'priority' => ['sometimes', 'string', 'in:high,normal,low'],
            'search' => ['sometimes', 'string', 'min:2', 'max:200'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
        ];

        if ($includePagination) {
            $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function filteredQuery(Request $request, array $validated)
    {
        return $request->user()
            ->notifications()
            ->when(
                array_key_exists('unread', $validated),
                fn ($query) => (bool) $validated['unread'] ? $query->whereNull('read_at') : $query->whereNotNull('read_at')
            )
            ->when(
                isset($validated['category']),
                fn ($query) => $query->where('data->category', $validated['category'])
            )
            ->when(
                isset($validated['categories']),
                fn ($query) => $query->whereIn('data->category', $validated['categories'])
            )
            ->when(
                isset($validated['priority']),
                fn ($query) => $query->where('data->priority', $validated['priority'])
            )
            ->when(
                isset($validated['search']),
                fn ($query) => $query
                    ->where(function ($subQuery) use ($validated): void {
                        $search = $validated['search'];
                        $subQuery
                            ->where('data->title', 'like', '%'.$search.'%')
                            ->orWhere('data->message', 'like', '%'.$search.'%');
                    })
            )
            ->when(
                isset($validated['created_from']),
                fn ($query) => $query->whereDate('created_at', '>=', $validated['created_from'])
            )
            ->when(
                isset($validated['created_to']),
                fn ($query) => $query->whereDate('created_at', '<=', $validated['created_to'])
            );
    }
}
