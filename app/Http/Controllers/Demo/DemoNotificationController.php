<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Demo\DemoActorResolver;
use App\Services\Notifications\NotificationHealthService;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemoNotificationController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly NotificationHealthService $notificationHealthService,
        private readonly NotificationPreferenceService $preferenceService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        $notifications = $actor->notifications()->latest()->paginate(15)->withQueryString();

        $deliveries = NotificationDelivery::query()
            ->with('document')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $actor->id)
            ->latest()
            ->limit(15)
            ->get();

        $health = $this->notificationHealthService->summary();

        $this->recordInspectorView($request, 'notifications.index', [
            'status' => 'success',
            'message' => 'Ouverture des notifications.',
            'actor_id' => $actor->id,
            'notifications_count' => $notifications->total(),
            'unread_notifications_count' => $actor->unreadNotifications()->count(),
            'deliveries_count' => $deliveries->count(),
        ]);

        $preference = $this->preferenceService->forUser($actor);

        return view('demo.notifications', [
            ...$this->shared($request),
            'notifications' => $notifications,
            'deliveries' => $deliveries,
            'health' => $health,
            'preference' => $preference,
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): RedirectResponse
    {
        $actor = $this->actor($request);
        $notification = $actor->notifications()->whereKey($notificationId)->firstOrFail();
        $wasUnread = $notification->read_at === null;

        return $this->runDemoAction(
            function () use ($notification): void {
                $notification->markAsRead();
            },
            'Notification marquee comme lue.',
            $request,
            'notifications.read',
            [
                'actor_id' => $actor->id,
                'notification_id' => $notificationId,
                'notification_type' => $notification->type,
                'was_unread' => $wasUnread,
            ]
        );
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $booleanFields = [
            'notify_reservation_updates_in_app',
            'notify_reservation_updates_email',
            'notify_driver_planning_in_app',
            'notify_driver_planning_email',
            'notify_admin_workflow_in_app',
            'notify_admin_workflow_email',
            'notify_last_minute_cancellation_in_app',
            'notify_last_minute_cancellation_email',
        ];

        $data = [];
        foreach ($booleanFields as $field) {
            $data[$field] = $request->boolean($field);
        }

        $request->validate([
            'digest_frequency' => ['nullable', 'string', 'in:none,daily,weekly'],
            'quiet_hours_start' => ['nullable', 'integer', 'min:0', 'max:23'],
            'quiet_hours_end' => ['nullable', 'integer', 'min:0', 'max:23'],
        ]);

        $data['digest_frequency'] = $request->input('digest_frequency', 'none');
        $data['quiet_hours_start'] = $request->filled('quiet_hours_start') ? (int) $request->input('quiet_hours_start') : null;
        $data['quiet_hours_end'] = $request->filled('quiet_hours_end') ? (int) $request->input('quiet_hours_end') : null;

        return $this->runDemoAction(function () use ($actor, $data): void {
            $this->preferenceService->update($actor, $data);
        }, 'Preferences de notification mises a jour.');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $unreadCount = $actor->unreadNotifications()->count();

        return $this->runDemoAction(
            function () use ($actor): void {
                $actor->unreadNotifications()->update(['read_at' => now()]);
            },
            'Notifications marquees comme lues.',
            $request,
            'notifications.read_all',
            [
                'actor_id' => $actor->id,
                'unread_notifications_count' => $unreadCount,
            ]
        );
    }
}
