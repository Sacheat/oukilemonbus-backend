<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Purge les notifications in-app et les logs de livraison selon la retention configuree';

    public function handle(): int
    {
        $inAppDays = (int) config('notifications.retention.in_app_days', 90);
        $deliveryDays = (int) config('notifications.retention.delivery_logs_days', 180);
        $failedDays = (int) config('notifications.retention.failed_delivery_logs_days', 365);

        $deletedInApp = DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays($inAppDays))
            ->delete();

        $deletedSentDeliveries = NotificationDelivery::query()
            ->where('status', '!=', 'failed')
            ->where('created_at', '<', now()->subDays($deliveryDays))
            ->delete();

        $deletedFailedDeliveries = NotificationDelivery::query()
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subDays($failedDays))
            ->delete();

        $this->info("Notifications in-app supprimees: {$deletedInApp}");
        $this->info("Logs de livraison (sent/pending) supprimes: {$deletedSentDeliveries}");
        $this->info("Logs de livraison (failed) supprimes: {$deletedFailedDeliveries}");

        return self::SUCCESS;
    }
}

