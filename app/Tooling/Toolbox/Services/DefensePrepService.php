<?php

declare(strict_types=1);

namespace App\Tooling\Toolbox\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

final class DefensePrepService
{
    /**
     * Vérifie que tout est prêt avant une démo / présentation.
     * Retourne un tableau de résultats [check, status, detail, hint].
     */
    public function prepareDemo(): array
    {
        return [
            $this->checkDatabase(),
            $this->checkDemoUsers(),
            $this->checkPermissions(),
            $this->checkQueue(),
            $this->checkConflicts(),
        ];
    }

    /**
     * Retourne les règles métier actives depuis le config du projet.
     */
    public function businessRules(): array
    {
        $configKey = (string) config('tooling.toolbox.business_rules.config_key', 'reservations');
        $labelDefs = (array) config('tooling.toolbox.business_rules.labels', []);
        $rules     = (array) config($configKey, []);

        $result = [];

        foreach ($labelDefs as $key => $def) {
            if (! array_key_exists($key, $rules)) {
                continue;
            }

            $value = $rules[$key];

            $result[] = [
                'key'   => $key,
                'label' => $def['label'] ?? $key,
                'value' => is_array($value) ? implode(', ', $value) : (string) $value,
                'unit'  => $def['unit'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Compte les fichiers PHP dans les répertoires configurés.
     */
    public function architecture(): array
    {
        $paths  = (array) config('tooling.toolbox.architecture.paths', []);
        $result = [];

        foreach ($paths as $label => $relativePath) {
            $fullPath = base_path($relativePath);
            $count    = 0;

            // Récursif : les services et les tests sont rangés en sous-dossiers (Services/Reservations…).
            if (is_dir($fullPath)) {
                $count = collect(File::allFiles($fullPath))
                    ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
                    ->count();
            }

            $result[] = [
                'label' => $label,
                'count' => $count,
                'path'  => $relativePath,
            ];
        }

        return $result;
    }

    /**
     * Compte les enregistrements dans les tables configurées.
     */
    public function dbStats(): array
    {
        $statDefs = (array) config('tooling.toolbox.db_stats', []);
        $result   = [];

        foreach ($statDefs as $def) {
            try {
                $query = DB::table($def['table']);

                foreach ($def['where'] ?? [] as $column => $value) {
                    $query = $value === null
                        ? $query->whereNull($column)
                        : $query->where($column, $value);
                }

                $count = $query->count();
            } catch (Throwable) {
                $count = '—';
            }

            $result[] = ['label' => $def['label'], 'count' => $count];
        }

        return $result;
    }

    /**
     * Retourne les routes API internes groupées par module + les APIs externes configurées.
     */
    public function apiCatalog(): array
    {
        return [
            'internal' => $this->internalRoutes(),
            'external' => (array) config('tooling.toolbox.api_catalog.external', []),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Checks pour prepareDemo()
    // ─────────────────────────────────────────────────────────────────────────

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $version = DB::selectOne('SELECT VERSION() as v')?->v ?? '?';

            return $this->check('Connexion base de données', 'ok', "MySQL {$version}", null);
        } catch (Throwable $e) {
            return $this->check('Connexion base de données', 'error', $e->getMessage(), 'Vérifier DB_HOST et DB_PASSWORD dans le .env');
        }
    }

    private function checkDemoUsers(): array
    {
        $pattern  = (string) config('tooling.toolbox.demo.email_pattern', '.test@');
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        try {
            $total    = $userModel::where('email', 'like', '%' . $pattern . '%')->count();
            $verified = $userModel::where('email', 'like', '%' . $pattern . '%')
                ->whereNotNull('email_verified_at')
                ->count();

            if ($total === 0) {
                return $this->check('Utilisateurs démo', 'warn', 'Aucun compte fictif trouvé', 'Lancer [Maintenance → Réparer rôles / permissions]');
            }

            if ($verified < $total) {
                $diff = $total - $verified;
                return $this->check('Utilisateurs démo', 'warn', "{$diff}/{$total} email(s) non vérifiés", 'Lancer [Maintenance → Valider emails fictifs]');
            }

            return $this->check('Utilisateurs démo', 'ok', "{$total} comptes vérifiés", null);
        } catch (Throwable $e) {
            return $this->check('Utilisateurs démo', 'error', $e->getMessage(), null);
        }
    }

    private function checkPermissions(): array
    {
        try {
            $roleCount = Role::count();

            if ($roleCount === 0) {
                return $this->check('Rôles & permissions', 'error', 'Aucun rôle en base', 'Lancer [Maintenance → Réparer rôles / permissions]');
            }

            return $this->check('Rôles & permissions', 'ok', "{$roleCount} rôle(s) seedé(s)", null);
        } catch (Throwable $e) {
            return $this->check('Rôles & permissions', 'error', $e->getMessage(), null);
        }
    }

    private function checkQueue(): array
    {
        try {
            $stuck  = DB::table('jobs')->whereNull('reserved_at')->where('created_at', '<', time() - 300)->count();
            $failed = DB::table('failed_jobs')->count();

            if ($stuck > 0) {
                return $this->check('Queue worker', 'warn', "{$stuck} job(s) bloqués > 5 min", 'docker compose up -d queue');
            }

            if ($failed > 0) {
                return $this->check('Queue worker', 'warn', "{$failed} job(s) en échec", 'php artisan queue:retry all');
            }

            return $this->check('Queue worker', 'ok', 'Aucun job bloqué ni en échec', null);
        } catch (Throwable $e) {
            return $this->check('Queue worker', 'error', $e->getMessage(), null);
        }
    }

    private function checkConflicts(): array
    {
        try {
            $count = DB::table('reservation_conflicts')->whereNull('resolved_at')->count();

            if ($count > 0) {
                return $this->check('Conflits actifs', 'warn', "{$count} conflit(s) non résolus", 'Les résoudre via l\'interface avant la démo');
            }

            return $this->check('Conflits actifs', 'ok', 'Aucun conflit non résolu', null);
        } catch (Throwable) {
            return $this->check('Conflits actifs', 'ok', 'Table absente (non critique)', null);
        }
    }

    private function check(string $label, string $status, string $detail, ?string $hint): array
    {
        return compact('label', 'status', 'detail', 'hint');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalogue des routes internes
    // ─────────────────────────────────────────────────────────────────────────

    private function internalRoutes(): array
    {
        try {
            return collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($r): bool =>
                    str_starts_with($r->uri(), 'api/')
                    && ! str_starts_with($r->uri(), 'api/admin/tooling')
                    && ! str_contains($r->uri(), 'signed')
                )
                ->map(fn ($r): array => [
                    'method'     => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->implode('|'),
                    'uri'        => '/' . $r->uri(),
                    'permission' => collect($r->middleware())
                        ->filter(fn ($m) => str_starts_with((string) $m, 'permission:'))
                        ->map(fn ($m) => str_replace('permission:', '', (string) $m))
                        ->first() ?? '—',
                ])
                ->groupBy(fn (array $r): string =>
                    Str::of($r['uri'])->after('/api/')->before('/')->title()->toString()
                )
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }
}
