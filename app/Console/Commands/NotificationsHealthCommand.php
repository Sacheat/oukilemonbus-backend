<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationHealthService;
use Illuminate\Console\Command;

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
            $this->line((string) json_encode([
                'status' => $isHealthy ? 'ok' : 'alert',
                'data' => $summary,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
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

        return $isHealthy ? self::SUCCESS : self::FAILURE;
    }
}
