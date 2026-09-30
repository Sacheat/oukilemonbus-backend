<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Tableau de bord de l'espace gestion (admin, agents communaux) : permission dashboard.view.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->dashboardService->summary()]);
    }
}
