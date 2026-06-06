<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\DevTools\DebugService;
use App\Services\DevTools\DefensePrepService;
use Illuminate\Http\JsonResponse;

final class ToolboxController extends Controller
{
    public function __construct(
        private readonly DefensePrepService $defensePrepService,
        private readonly DebugService       $debugService,
    ) {}

    public function data(): JsonResponse
    {
        abort_unless(config('backend_inspector.enabled', false), 404);

        return response()->json([
            'db_stats'       => $this->defensePrepService->dbStats(),
            'business_rules' => $this->defensePrepService->businessRules(),
            'architecture'   => $this->defensePrepService->architecture(),
            'users'          => $this->debugService->allUsers(),
            'permissions'    => $this->debugService->permissionsMatrix(),
        ]);
    }
}
