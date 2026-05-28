<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\NotificationDigestQueue;
use App\Models\User;
use App\Services\Notifications\NotificationDispatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_can_list_and_mark_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $notification = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'event_key' => 'reservation.confirmed',
                'title' => 'Reservation confirmee',
                'message' => 'Votre reservation est confirmee.',
            ],
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $notification->id);

        $this->patchJson("/api/notifications/{$notification->id}/read")
            ->assertNoContent();

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_user_can_read_and_update_notification_preferences(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('data.notify_reservation_updates_email', true);

        $this->patchJson('/api/notifications/preferences', [
            'notify_reservation_updates_email' => false,
            'notify_driver_planning_email' => false,
            'digest_frequency' => 'hourly',
            'quiet_hours_start' => 22,
            'quiet_hours_end' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('data.notify_reservation_updates_email', false)
            ->assertJsonPath('data.notify_driver_planning_email', false)
            ->assertJsonPath('data.digest_frequency', 'hourly')
            ->assertJsonPath('data.quiet_hours_start', 22)
            ->assertJsonPath('data.quiet_hours_end', 7);
    }

    public function test_user_can_get_unread_count_filter_and_bulk_mark_as_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $firstUnread = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'event_key' => 'reservation.confirmed',
                'category' => 'reservation_updates',
                'priority' => 'normal',
                'title' => 'Reservation confirmee',
                'message' => 'Votre reservation est confirmee.',
            ],
            'read_at' => null,
        ]);

        $secondUnread = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'event_key' => 'reservation.cancelled',
                'category' => 'last_minute_cancellation',
                'priority' => 'high',
                'title' => 'Reservation annulee',
                'message' => 'Annulation de derniere minute.',
            ],
            'read_at' => null,
        ]);

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'event_key' => 'reservation.approved',
                'category' => 'reservation_updates',
                'priority' => 'normal',
                'title' => 'Reservation approuvee',
                'message' => 'Votre reservation est approuvee.',
            ],
            'read_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->getJson('/api/notifications?unread=1&category=last_minute_cancellation&priority=high')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondUnread->id);

        $this->patchJson('/api/notifications/read', [
            'ids' => [$firstUnread->id, $secondUnread->id],
        ])->assertNoContent();

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_dispatch_service_is_idempotent_with_same_dedupe_signature(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('user');

        /** @var NotificationDispatchService $dispatcher */
        $dispatcher = app(NotificationDispatchService::class);

        $users = collect([$user]);
        $dispatcher->dispatch(
            users: $users,
            eventKey: 'reservation.confirmed',
            category: 'reservation_updates',
            title: 'Reservation confirmee',
            message: 'Votre reservation est confirmee.'
        );
        $dispatcher->dispatch(
            users: $users,
            eventKey: 'reservation.confirmed',
            category: 'reservation_updates',
            title: 'Reservation confirmee',
            message: 'Votre reservation est confirmee.'
        );

        $this->assertSame(
            2,
            NotificationDelivery::query()->count(),
            'Une seule livraison par canal doit etre conservee.'
        );
    }

    public function test_non_urgent_mail_can_be_queued_in_digest(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('user');

        $user->notificationPreference()->create([
            'notify_reservation_updates_in_app' => true,
            'notify_reservation_updates_email' => true,
            'notify_driver_planning_in_app' => true,
            'notify_driver_planning_email' => true,
            'notify_admin_workflow_in_app' => true,
            'notify_admin_workflow_email' => true,
            'notify_last_minute_cancellation_in_app' => true,
            'notify_last_minute_cancellation_email' => true,
            'digest_frequency' => 'hourly',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        /** @var NotificationDispatchService $dispatcher */
        $dispatcher = app(NotificationDispatchService::class);

        $dispatcher->dispatch(
            users: collect([$user]),
            eventKey: 'reservation.confirmed',
            category: 'reservation_updates',
            title: 'Reservation confirmee',
            message: 'Votre reservation est confirmee.',
            urgent: false
        );

        $this->assertSame(1, NotificationDelivery::query()->where('channel', 'database')->count());
        $this->assertSame(0, NotificationDelivery::query()->where('channel', 'mail')->count());
        $this->assertSame(1, NotificationDigestQueue::query()->count());
    }
}
