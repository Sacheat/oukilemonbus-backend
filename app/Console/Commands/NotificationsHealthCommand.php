<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationHealthService;
use Illuminate\Console\Command;

/**
 * Expose les métriques de santé du système de notifications.
 * Utilisée manuellement pour diagnostiquer des livraisons bloquées ou des digests en retard.
 * Le code de retour (0/1) permet l'intégration dans des scripts de monitoring.
 */
class NotificationsHealthCommand extends Command
{
    protected $signature = 'notifications:health {--json : Affiche le resultat en JSON}';

    protected $description = 'Expose des indicateurs de sante du module notifications (async + digest)';

    public function __construct(
        private readonly NotificationHealthService $healthService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->healthService->summary();
        $isHealthy = $this->healthService->isHealthy($summary);

        if ((bool) $this->option('json')) {
            // Le format JSON est prévu pour les outils de monitoring externes (Grafana, scripts CI).
            $this->line((string) json_encode([
                'status' => $isHealthy ? 'ok' : 'alert',
                'data' => $summary,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            // Format texte brut lisible directement dans le terminal.
            $this->info('Notifications health check');
            $this->line('deliveries_pending_total='.$summary['deliveries_pending_total']);
            $this->line('deliveries_failed_last_24h='.$summary['deliveries_failed_last_24h']);
            $this->line('deliveries_high_pending_over_5m='.$summary['deliveries_high_pending_over_5m']);
            $this->line('digests_pending_ready='.$summary['digests_pending_ready']);
            $this->line('digests_late_over_30m='.$summary['digests_late_over_30m']);
            $this->line('oldest_pending_delivery_age_seconds='.$summary['oldest_pending_delivery_age_seconds']);
            $this->line('checked_at='.$summary['checked_at']);
            $this->line('status='.($isHealthy ? 'ok' : 'alert'));
        }

        // Retourne FAILURE si un seuil est dépassé, ce qui permet d'alerter un outil de supervision.
        return $isHealthy ? self::SUCCESS : self::FAILURE;
    }
}
