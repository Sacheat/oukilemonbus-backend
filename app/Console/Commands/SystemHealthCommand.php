<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;

/**
 * Vérifie l'état de santé global de l'application et de ses dépendances.
 * Couvre l'infrastructure (DB, cache, queue, stockage), les API externes (ORS, GeoNames)
 * et les modules applicatifs (notifications, configuration).
 * Le code de retour (0/1) permet l'intégration dans des scripts de déploiement ou de monitoring.
 */
class SystemHealthCommand extends Command
{
    protected $signature = 'app:health {--json : Affiche le résultat en JSON}';

    protected $description = 'Vérifie l\'état des services critiques (DB, cache, queue, stockage, ORS, notifications, config)';

    // Regroupement des checks par catégorie pour l'affichage en tableau.
    private const GROUPS = [
        'Infrastructure' => [
            'database' => 'Base de données',
            'cache'    => 'Cache',
            'queue'    => 'Queue worker',
            'storage'  => 'Stockage fichiers',
        ],
        'API externes' => [
            'ors_geocoding' => 'ORS Géocodage',
            'ors_routing'   => 'ORS Itinéraire',
            'geonames'      => 'GeoNames',
        ],
        'Application' => [
            'notifications' => 'Notifications',
            'config'        => 'Configuration',
        ],
    ];

    // Conseils de résolution affichés sous chaque point d'attention non-OK.
    private const HINTS = [
        'database'      => 'Vérifier DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD dans le .env',
        'cache'         => 'Vérifier CACHE_STORE et la connexion à la base de données',
        'queue'         => 'Ajouter la queue dans QUEUE_WORKER_QUEUES (.env), puis relancer : docker compose up -d --force-recreate queue',
        'storage'       => 'Vérifier les droits du dossier storage/ : chmod -R 775 storage/ bootstrap/cache/',
        'ors_geocoding' => 'Vérifier ORS_API_KEY dans le .env et la connectivité réseau vers api.openrouteservice.org',
        'ors_routing'   => 'Vérifier ORS_API_KEY dans le .env et la connectivité réseau vers api.openrouteservice.org',
        'geonames'      => 'Vérifier GEONAMES_USERNAME dans le .env — compte gratuit sur geonames.org',
        'notifications' => 'Consulter les logs du worker : docker logs bus-communal-queue',
        'config'        => 'Compléter les variables manquantes dans le .env',
    ];

    public function __construct(
        private readonly SystemHealthService $healthService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks  = $this->healthService->check();
        $healthy = $this->healthService->isHealthy($checks);

        if ((bool) $this->option('json')) {
            // Format JSON destiné aux outils de monitoring (ex: script de déploiement, Grafana).
            $this->line((string) json_encode([
                'status'     => $healthy ? 'ok' : 'error',
                'checks'     => $checks,
                'checked_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->printHeader($checks, $healthy);
        $this->printGroups($checks);
        $this->printAttentionPoints($checks);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function printHeader(array $checks, bool $healthy): void
    {
        $checkedAt = now()->setTimezone('Europe/Brussels')->format('d/m/Y à H:i:s');
        $counts    = array_count_values(array_column($checks, 'status'));

        $parts = array_filter([
            ($counts['ok']    ?? 0) > 0 ? '<fg=green>'.($counts['ok']).' OK</>'       : null,
            ($counts['warn']  ?? 0) > 0 ? '<fg=yellow>'.($counts['warn']).' WARN</>'  : null,
            ($counts['error'] ?? 0) > 0 ? '<fg=red>'.($counts['error']).' ERROR</>'   : null,
        ]);

        $globalBadge = $healthy
            ? '<fg=green;options=bold>OPÉRATIONNEL</>'
            : '<fg=red;options=bold>DÉGRADÉ</>';

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>OuKiLéMonBus — System Health Check</>');
        $this->line("  Vérifié le {$checkedAt}");
        $this->newLine();
        $this->line('  État global : '.$globalBadge.'  —  '.implode('  ', $parts));
        $this->newLine();
    }

    private function printGroups(array $checks): void
    {
        foreach (self::GROUPS as $groupName => $labels) {
            $this->line("  <options=bold>{$groupName}</>");

            $rows = [];
            foreach ($labels as $key => $label) {
                // Si un check est absent du résultat, on le considère en erreur par sécurité.
                $check  = $checks[$key] ?? ['status' => 'error', 'details' => 'Donnée absente'];
                $rows[] = [
                    $label,
                    $this->statusBadge($check['status']),
                    $check['details'] ?? '—',
                    isset($check['duration_ms']) ? $check['duration_ms'].'ms' : '—',
                ];
            }

            $this->table(['Composant', 'Statut', 'Détails', 'Durée'], $rows);
        }
    }

    private function printAttentionPoints(array $checks): void
    {
        $nonOk = array_filter($checks, fn ($c) => $c['status'] !== 'ok');

        if ($nonOk === []) {
            return;
        }

        $this->line('  <options=bold>Points d\'attention</>');
        $this->newLine();

        foreach ($nonOk as $key => $check) {
            $icon = $check['status'] === 'warn' ? '<fg=yellow>⚠</>' : '<fg=red>✗</>';

            $this->line("  {$icon}  <options=bold>{$this->labelForKey($key)}</> ({$check['status']})");
            $this->line("     Détail : {$check['details']}");

            if (isset(self::HINTS[$key])) {
                $this->line('     → '.self::HINTS[$key]);
            }

            $this->newLine();
        }
    }

    private function labelForKey(string $key): string
    {
        foreach (self::GROUPS as $labels) {
            if (isset($labels[$key])) {
                return $labels[$key];
            }
        }

        return $key;
    }

    private function statusBadge(string $status): string
    {
        return match ($status) {
            'ok'    => '<fg=green>✓ OK</>',
            'warn'  => '<fg=yellow>⚠ WARN</>',
            default => '<fg=red>✗ ERROR</>',
        };
    }
}
