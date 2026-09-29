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

    public function test_quiet_hours_can_be_disabled_with_null(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->patchJson('/api/notifications/preferences', ['quiet_hours_start' => 22, 'quiet_hours_end' => 7])
            ->assertSuccessful();

        $this->patchJson('/api/notifications/preferences', ['quiet_hours_start' => null, 'quiet_hours_end' => null])
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_start', null)
            ->assertJsonPath('data.quiet_hours_end', null);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
    }

    public function test_quiet_hours_need_both_bounds_or_none(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/preferences')->assertOk();

        $message = 'Les heures silencieuses doivent avoir une heure de début et une heure de fin, ou aucune des deux pour les désactiver.';

        // Fin à null, début renseigné : erreur sur la fin.
        $this->patchJson('/api/notifications/preferences', ['quiet_hours_start' => 22, 'quiet_hours_end' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quiet_hours_end.0', $message)
            ->assertJsonMissingValidationErrors('quiet_hours_start');

        // Début seul envoyé : erreur sur la fin manquante.
        $this->patchJson('/api/notifications/preferences', ['quiet_hours_start' => 22])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quiet_hours_end.0', $message);

        // Fin seule envoyée : erreur sur le début.
        $this->patchJson('/api/notifications/preferences', ['quiet_hours_end' => 7])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quiet_hours_start.0', $message);

        // Les autres préférences restent modifiables sans toucher aux heures silencieuses.
        $this->patchJson('/api/notifications/preferences', ['digest_frequency' => 'daily'])
            ->assertOk();
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

    public function test_guest_cannot_access_notifications_health_endpoint(): void
    {
        $this->getJson('/api/notifications/health')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_cannot_access_notifications_health_endpoint(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/health')
            ->assertForbidden();
    }

    public function test_agent_can_access_notifications_health_endpoint(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'deliveries_pending_total',
                    'deliveries_failed_last_24h',
                    'deliveries_high_pending_over_5m',
                    'digests_pending_ready',
                    'digests_late_over_30m',
                    'oldest_pending_delivery_age_seconds',
                    'checked_at',
                ],
            ]);
    }

    public function test_notifications_health_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->getJson('/api/notifications/health')
                ->assertOk();
        }

        $this->getJson('/api/notifications/health')
            ->assertStatus(429);
    }

    public function test_user_can_get_notifications_summary_grouped_by_category_and_priority(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'reservation_updates',
                'priority' => 'normal',
                'title' => 'Reservation confirmee',
                'message' => 'Votre reservation est confirmee.',
            ],
            'read_at' => null,
        ]);

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'last_minute_cancellation',
                'priority' => 'high',
                'title' => 'Annulation urgente',
                'message' => 'Mission annulee a la derniere minute.',
            ],
            'read_at' => null,
        ]);

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'reservation_updates',
                'priority' => 'normal',
                'title' => 'Reservation approuvee',
                'message' => 'Votre reservation est approuvee.',
            ],
            'read_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.unread', 2)
            ->assertJsonPath('data.read', 1)
            ->assertJsonCount(2, 'data.by_category')
            ->assertJsonCount(2, 'data.by_priority');
    }

    public function test_user_can_mark_notifications_as_read_by_filter(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $highOne = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'last_minute_cancellation',
                'priority' => 'high',
                'title' => 'Annulation 1',
                'message' => 'Annulation urgente.',
            ],
            'read_at' => null,
        ]);

        $highTwo = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'last_minute_cancellation',
                'priority' => 'high',
                'title' => 'Annulation 2',
                'message' => 'Deuxieme annulation urgente.',
            ],
            'read_at' => null,
        ]);

        $normal = $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'data' => [
                'category' => 'reservation_updates',
                'priority' => 'normal',
                'title' => 'Info',
                'message' => 'Notification normale.',
            ],
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/notifications/read-by-filter?category=last_minute_cancellation&priority=high&unread=1')
            ->assertOk()
            ->assertJsonPath('data.marked_as_read', 2);

        $this->assertNotNull($highOne->fresh()->read_at);
        $this->assertNotNull($highTwo->fresh()->read_at);
        $this->assertNull($normal->fresh()->read_at);
    }
}
