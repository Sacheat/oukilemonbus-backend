<?php

declare(strict_types=1);

namespace App\Tooling\Toolbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Tooling\Toolbox\Services\DebugService;
use App\Tooling\Toolbox\Services\DefensePrepService;
use Illuminate\Http\JsonResponse;

/**
 * API lecture seule de la Dev Toolbox (/api/admin/tooling/toolbox/*).
 * Aucune route n'exécute d'action : les opérations (reset, backups, commandes) restent
 * réservées à la commande `php artisan dev:toolbox`. Seule exception du module : la relance et la
 * suppression des jobs en échec (FailedJobsController).
 */
final class ToolboxController extends Controller
{
    public function __construct(
        private readonly DefensePrepService $defensePrepService,
        private readonly DebugService $debugService,
        private readonly SystemHealthService $healthService,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json([
            'data' => [
                'db_stats' => $this->defensePrepService->dbStats(),
                'business_rules' => $this->defensePrepService->businessRules(),
                'architecture' => $this->defensePrepService->architecture(),
            ],
        ]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => $this->debugService->permissionsMatrix()]);
    }

    public function users(): JsonResponse
    {
        return response()->json(['data' => $this->debugService->allUsers()]);
    }

    public function apiCatalog(): JsonResponse
    {
        return response()->json(['data' => $this->defensePrepService->apiCatalog()]);
    }

    // Interroge aussi les API externes (ORS, GeoNames) : réponse potentiellement lente.
    public function health(): JsonResponse
    {
        $checks = $this->healthService->check();

        return response()->json([
            'data' => [
                'healthy' => $this->healthService->isHealthy($checks),
                'checks' => $checks,
            ],
        ]);
    }
}
