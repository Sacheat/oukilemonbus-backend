<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationDigestQueue;
use Carbon\CarbonInterface;

class NotificationHealthService
{
    public function summary(?CarbonInterface $now = null): array
    {
        $now ??= now();

        $pendingDeliveries = NotificationDelivery::query()
            ->where('status', DeliveryStatus::PENDING->value);

        $oldestPendingDelivery = (clone $pendingDeliveries)
            ->oldest('created_at')
            ->first();

        return [
            'deliveries_pending_total' => (clone $pendingDeliveries)->count(),
            'deliveries_failed_last_24h' => NotificationDelivery::query()
                ->where('status', DeliveryStatus::FAILED->value)
                ->where('created_at', '>=', $now->copy()->subDay())
                ->count(),
            'deliveries_high_pending_over_5m' => NotificationDelivery::query()
                ->where('status', DeliveryStatus::PENDING->value)
                ->where('priority', 'high')
                ->where('created_at', '<', $now->copy()->subMinutes(5))
                ->count(),
            'digests_pending_ready' => NotificationDigestQueue::query()
                ->whereNull('sent_at')
                ->where('available_at', '<=', $now)
                ->count(),
            'digests_late_over_30m' => NotificationDigestQueue::query()
                ->whereNull('sent_at')
                ->where('available_at', '<', $now->copy()->subMinutes(30))
                ->count(),
            'oldest_pending_delivery_age_seconds' => $oldestPendingDelivery?->created_at?->diffInSeconds($now) ?? 0,
            'checked_at' => $now->toIso8601String(),
        ];
    }

    public function isHealthy(array $summary): bool
    {
        return $summary['deliveries_failed_last_24h'] <= (int) config('notifications.observability.max_failed_deliveries_24h', 20)
            && $summary['deliveries_high_pending_over_5m'] <= (int) config('notifications.observability.max_high_pending_over_5m', 0)
            && $summary['digests_late_over_30m'] <= (int) config('notifications.observability.max_late_digests_over_30m', 10);
    }
}
