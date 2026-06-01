<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Demo\DemoActorResolver;
use App\Services\Notifications\NotificationHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemoNotificationController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly NotificationHealthService $notificationHealthService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        return view('demo.notifications', [
            ...$this->shared($request),
            'notifications' => $actor->notifications()->latest()->paginate(15)->withQueryString(),
            'deliveries' => NotificationDelivery::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $actor->id)
                ->latest()
                ->limit(15)
                ->get(),
            'health' => $this->notificationHealthService->summary(),
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): RedirectResponse
    {
        $actor = $this->actor($request);
        $notification = $actor->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return back()->with('status', 'Notification marquee comme lue.');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $actor->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Notifications marquees comme lues.');
    }
}
