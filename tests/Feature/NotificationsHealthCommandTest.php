<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\NotificationDigestQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_health_command_is_ok_when_thresholds_are_respected(): void
    {
        config()->set('notifications.observability.max_failed_deliveries_24h', 20);
        config()->set('notifications.observability.max_high_pending_over_5m', 1);
        config()->set('notifications.observability.max_late_digests_over_30m', 10);

        $user = User::factory()->create();

        $this->createDelivery(
            user: $user,
            status: 'pending',
            priority: 'normal',
            createdAt: now()->subMinutes(2),
        );

        $this->createDelivery(
            user: $user,
            status: 'failed',
            priority: 'normal',
            createdAt: now()->subHours(2),
        );

        NotificationDigestQueue::query()->create([
            'user_id' => $user->id,
            'event_key' => 'reservation.confirmed',
            'category' => 'reservation_updates',
            'priority' => 'normal',
            'title' => 'Reservation confirmee',
            'message' => 'Votre reservation est confirmee.',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'available_at' => now()->subMinutes(10),
            'sent_at' => null,
        ]);

        $this->artisan('notifications:health')
            ->expectsOutputToContain('status=ok')
            ->assertExitCode(0);
    }

    public function test_notifications_health_command_returns_alert_when_threshold_is_exceeded(): void
    {
        Carbon::setTestNow('2026-06-17 12:00:00');

        config()->set('notifications.observability.max_failed_deliveries_24h', 20);
        config()->set('notifications.observability.max_high_pending_over_5m', 0);
        config()->set('notifications.observability.max_late_digests_over_30m', 10);

        $user = User::factory()->create();

        $this->createDelivery(
            user: $user,
            status: 'pending',
            priority: 'high',
            createdAt: now()->subMinutes(7),
        );

        $this->artisan('notifications:health --json')
            ->expectsOutputToContain('"status":"alert"')
            ->assertExitCode(1);

        Carbon::setTestNow();
    }

    private function createDelivery(User $user, string $status, string $priority, Carbon $createdAt): void
    {
        NotificationDelivery::query()->insert([
            'event_id' => (string) Str::uuid(),
            'event_key' => 'reservation.confirmed',
            'dedupe_key' => hash('sha256', Str::uuid()->toString()),
            'attempt_id' => (string) Str::uuid(),
            'attempt_number' => 1,
            'notification_type' => 'App\\Notifications\\OperationalNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'channel' => 'database',
            'priority' => $priority,
            'recipient_email' => $user->email,
            'status' => $status,
            'provider' => 'database',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'sent_at' => $status === 'sent' ? $createdAt : null,
            'failed_at' => $status === 'failed' ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
