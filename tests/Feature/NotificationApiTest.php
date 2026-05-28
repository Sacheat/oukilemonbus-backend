<?php

namespace Tests\Feature;

use App\Models\User;
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
        ])
            ->assertOk()
            ->assertJsonPath('data.notify_reservation_updates_email', false)
            ->assertJsonPath('data.notify_driver_planning_email', false);
    }
}

