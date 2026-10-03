<?php

namespace App\Services;

use App\Services\Notifications\NotificationHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Vérifie l'état de tous les composants critiques de l'application.
 *
 * `check()` retourne un tableau de diagnostics indexés par composant,
 * chacun avec un statut ('ok', 'warn' ou 'error'), des détails et une durée en ms.
 *
 * Composants vérifiés :
 *   - database      : connexion PDO + version MySQL
 *   - cache         : écriture/lecture/suppression d'une clé temporaire
 *   - queue         : jobs failed, bloqués > 5 min, queues orphelines (non écoutées par le worker)
 *   - storage       : écriture/lecture sur le disque 'local'
 *   - ors_geocoding : appel /geocode/search ORS avec "Dinant"
 *   - ors_routing   : appel /v2/directions ORS Dinant → Namur
 *   - geonames      : appel searchJSON GeoNames avec "Dinant"
 *   - notifications : métriques NotificationHealthService (pending, failed, late)
 *   - mail          : connexion au serveur SMTP du mailer par défaut (EHLO + authentification, sans envoi) ;
 *                     'warn' si elle échoue (ex. quota Mailtrap épuisé : 535), 'ok' sans SMTP (log, array)
 *   - config        : présence des variables requises (APP_KEY, ORS_API_KEY, DB_*)
 *
 * `isHealthy()` retourne false si au moins un composant a le statut 'error'.
 */
class SystemHealthService
{
    public function __construct(
        private readonly NotificationHealthService $notificationHealthService
    ) {}

    public function check(): array
    {
        return [
            'database'      => $this->checkDatabase(),
            'cache'         => $this->checkCache(),
            'queue'         => $this->checkQueue(),
            'storage'       => $this->checkStorage(),
            'ors_geocoding' => $this->checkOrsGeocoding(),
            'ors_routing'   => $this->checkOrsRouting(),
            'geonames'      => $this->checkGeonames(),
            'notifications' => $this->checkNotifications(),
            'mail'          => $this->checkMail(),
            'config'        => $this->checkConfig(),
        ];
    }

    public function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return false;
            }
        }

        return true;
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo();
            $version = DB::selectOne('SELECT VERSION() as v')->v;
            $db = config('database.connections.'.config('database.default').'.database');
            $host = config('database.connections.'.config('database.default').'.host');

            return [
                'status'      => 'ok',
                'details'     => "{$db} @ {$host} (v{$version})",
                'duration_ms' => $this->ms($start),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkCache(): array
    {
        $start = microtime(true);

        try {
            $key = 'health_check_'.uniqid();
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return ['status' => 'error', 'details' => 'Incohérence lecture/écriture', 'duration_ms' => $this->ms($start)];
            }

            return [
                'status'      => 'ok',
                'details'     => 'driver='.config('cache.default'),
                'duration_ms' => $this->ms($start),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkQueue(): array
    {
        try {
            $servedQueues = array_map('trim', explode(',', (string) config('queue.worker_queues', 'default')));

            $failedCount = DB::table('failed_jobs')->count();

            // jobs.created_at est un timestamp Unix (integer), pas un datetime
            $stuckCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('created_at', '<', time() - 300)
                ->count();

            // Queues qui ont des jobs en attente mais que le worker n'écoute pas
            $orphaned = array_values(array_diff(
                DB::table('jobs')->whereNull('reserved_at')->distinct()->pluck('queue')->toArray(),
                $servedQueues
            ));

            if ($orphaned !== []) {
                return [
                    'status'          => 'warn',
                    'details'         => 'Jobs en attente sur queues non écoutées : '.implode(', ', $orphaned),
                    'orphaned_queues' => $orphaned,
                    'served_queues'   => $servedQueues,
                    'duration_ms'     => null,
                ];
            }

            $status = ($failedCount > 0 || $stuckCount > 0) ? 'warn' : 'ok';

            return [
                'status'               => $status,
                'details'              => 'queues='.implode(',', $servedQueues).", failed={$failedCount}, bloqués>5min={$stuckCount}",
                'failed_jobs'          => $failedCount,
                'stuck_jobs_over_5min' => $stuckCount,
                'duration_ms'          => null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => null];
        }
    }

    private function checkStorage(): array
    {
        $start = microtime(true);

        try {
            $path = 'health_check_'.uniqid().'.tmp';
            Storage::disk('local')->put($path, 'ok');
            $content = Storage::disk('local')->get($path);
            Storage::disk('local')->delete($path);

            if ($content !== 'ok') {
                return ['status' => 'error', 'details' => 'Incohérence lecture/écriture', 'duration_ms' => $this->ms($start)];
            }

            return [
                'status'      => 'ok',
                'details'     => 'disk=local, accessible en écriture',
                'duration_ms' => $this->ms($start),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkOrsGeocoding(): array
    {
        $apiKey = config('services.openrouteservice.key');
        $baseUrl = config('services.openrouteservice.base_url');

        if (blank($apiKey)) {
            return ['status' => 'error', 'details' => 'ORS_API_KEY absente', 'duration_ms' => null];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(5)->get("{$baseUrl}/geocode/search", [
                'api_key'          => $apiKey,
                'text'             => 'Dinant, Belgique',
                'boundary.country' => 'BE',
                'size'             => 1,
            ]);

            $ms = $this->ms($start);

            if ($response->failed()) {
                return ['status' => 'error', 'details' => "HTTP {$response->status()}", 'duration_ms' => $ms];
            }

            $count = count($response->json('features', []));

            return [
                'status'      => 'ok',
                'details'     => "{$count} résultat(s) pour 'Dinant'",
                'duration_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkOrsRouting(): array
    {
        $apiKey = config('services.openrouteservice.key');
        $baseUrl = config('services.openrouteservice.base_url');

        if (blank($apiKey)) {
            return ['status' => 'error', 'details' => 'ORS_API_KEY absente', 'duration_ms' => null];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(5)->withHeaders([
                'Authorization' => $apiKey,
                'Content-Type'  => 'application/json',
            ])->post("{$baseUrl}/v2/directions/driving-car", [
                'coordinates' => [[4.9122, 50.2607], [4.8531, 50.4674]], // Dinant → Namur
            ]);

            $ms = $this->ms($start);

            if ($response->failed()) {
                return ['status' => 'error', 'details' => "HTTP {$response->status()}", 'duration_ms' => $ms];
            }

            $distanceKm = round($response->json('routes.0.summary.distance', 0) / 1000, 1);

            return [
                'status'      => 'ok',
                'details'     => "Dinant→Namur : {$distanceKm} km",
                'duration_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkGeonames(): array
    {
        $username = config('services.geonames.username');
        $baseUrl  = rtrim((string) config('services.geonames.base_url', 'http://api.geonames.org'), '/');

        if (blank($username)) {
            return ['status' => 'error', 'details' => 'GEONAMES_USERNAME absent', 'duration_ms' => null];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(5)->get("{$baseUrl}/searchJSON", [
                'name_equals' => 'Dinant',
                'country'     => 'BE',
                'maxRows'     => 1,
                'style'       => 'SHORT',
                'username'    => $username,
            ]);

            $ms    = $this->ms($start);
            $count = $response->json('totalResultsCount', 0);

            [$status, $details] = match(true) {
                $response->failed() => ['error', "HTTP {$response->status()}"],
                $count === 0        => ['warn',  "Aucun résultat pour 'Dinant' — username invalide ?"],
                default             => ['ok',    "{$count} résultat(s) pour 'Dinant'"],
            };

            return ['status' => $status, 'details' => $details, 'duration_ms' => $ms];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => $this->ms($start)];
        }
    }

    private function checkNotifications(): array
    {
        try {
            $summary = $this->notificationHealthService->summary();
            $healthy = $this->notificationHealthService->isHealthy($summary);

            return [
                'status'      => $healthy ? 'ok' : 'warn',
                'details'     => "en_attente={$summary['deliveries_pending_total']}, échoués_24h={$summary['deliveries_failed_last_24h']}, digests_en_retard={$summary['digests_late_over_30m']}",
                'duration_ms' => null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'details' => $e->getMessage(), 'duration_ms' => null];
        }
    }

    private function checkMail(): array
    {
        $start = microtime(true);
        $mailer = (string) config('mail.default');

        if (config("mail.mailers.{$mailer}.transport") !== 'smtp') {
            return ['status' => 'ok', 'details' => "mailer={$mailer} : aucun envoi SMTP", 'duration_ms' => null];
        }

        $server = config("mail.mailers.{$mailer}.host").':'.config("mail.mailers.{$mailer}.port");

        try {
            $transport = Mail::mailer($mailer)->getSymfonyTransport();

            // Délai court : un serveur injoignable ne doit pas bloquer la page de santé (défaut PHP : 60 s).
            if ($transport instanceof EsmtpTransport && $transport->getStream() instanceof SocketStream) {
                $transport->getStream()->setTimeout(5);
            }

            $transport->start();
            $transport->stop();

            return [
                'status'      => 'ok',
                'details'     => "SMTP {$server} joignable (EHLO".(config("mail.mailers.{$mailer}.username") ? ' + authentification' : '').')',
                'duration_ms' => $this->ms($start),
            ];
        } catch (\Throwable $e) {
            // Le message de Symfony cite l'identifiant SMTP : ne pas l'afficher dans la page de santé.
            $username = (string) config("mail.mailers.{$mailer}.username");
            $message = $username !== '' ? str_replace($username, '[masqué]', $e->getMessage()) : $e->getMessage();

            return [
                'status'      => 'warn',
                'details'     => "SMTP {$server} : ".$message,
                'duration_ms' => $this->ms($start),
            ];
        }
    }

    private function checkConfig(): array
    {
        $required = [
            'APP_KEY'     => config('app.key'),
            'ORS_API_KEY' => config('services.openrouteservice.key'),
            'DB_HOST'     => config('database.connections.'.config('database.default').'.host'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
        ];

        $missing = array_keys(array_filter($required, fn ($v) => blank($v)));

        if ($missing !== []) {
            return [
                'status'  => 'error',
                'details' => 'Variables absentes : '.implode(', ', $missing),
                'missing' => $missing,
            ];
        }

        return [
            'status'  => 'ok',
            'details' => count($required).' variables requises présentes',
        ];
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
