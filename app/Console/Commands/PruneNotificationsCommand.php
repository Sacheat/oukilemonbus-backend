<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Purge les notifications et les logs de livraison anciens pour éviter la croissance infinie des tables.
 * Appelée par le scheduler selon la fréquence configurée (typiquement hebdomadaire).
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Purge les notifications in-app et les logs de livraison selon la retention configuree';

    public function handle(): int
    {
        // Les durées de rétention sont configurables dans config/notifications.php.
        $inAppDays    = (int) config('notifications.retention.in_app_days', 90);
        $deliveryDays = (int) config('notifications.retention.delivery_logs_days', 180);
        // Les livraisons échouées sont conservées plus longtemps pour faciliter le diagnostic.
        $failedDays   = (int) config('notifications.retention.failed_delivery_logs_days', 365);

        $deletedInApp = DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays($inAppDays))
            ->delete();

        // Les livraisons réussies ou en attente sont moins critiques à conserver.
        $deletedSentDeliveries = NotificationDelivery::query()
            ->where('status', '!=', 'failed')
            ->where('created_at', '<', now()->subDays($deliveryDays))
            ->delete();

        // Les livraisons échouées ont leur propre durée de rétention, distincte des autres.
        $deletedFailedDeliveries = NotificationDelivery::query()
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subDays($failedDays))
            ->delete();

        $this->info("Notifications in-app supprimées : {$deletedInApp}");
        $this->info("Logs de livraison (sent/pending) supprimés : {$deletedSentDeliveries}");
        $this->info("Logs de livraison (failed) supprimés : {$deletedFailedDeliveries}");

        return self::SUCCESS;
    }
}

