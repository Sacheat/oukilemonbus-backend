<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\PlanningIndexRequest;
use App\Http\Resources\PlanningItemResource;
use App\Services\Planning\PlanningExportService;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PlanningController extends Controller
{
    public function __construct(
        private readonly PlanningService $planningService,
        private readonly PlanningExportService $planningExportService
    ) {}

    public function index(PlanningIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Les disponibilites detaillees sont reservees aux roles internes.
        $includeAvailability = $request->wantsAvailabilityDetails()
            && $user->hasAnyRole(['admin', 'agent_communal']);

        $filters = $request->planningFilters();

        $items = $this->planningService->forPeriod(
            $user,
            $validated['start_date'],
            $validated['end_date'],
            $includeAvailability,
            $request->planningStatuses(),
            $filters
        );

        return response()->json([
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'filters' => $filters,
            'data' => PlanningItemResource::collection($items)->resolve($request),
        ]);
    }

    public function export(PlanningIndexRequest $request): Response
    {
        $validated = $request->validated();

        $items = $this->planningService->forPeriod(
            $request->user(),
            $validated['start_date'],
            $validated['end_date'],
            false,
            $request->planningStatuses(),
            $request->planningFilters()
        );

        return $this->planningExportService->download(
            $items,
            $validated['start_date'],
            $validated['end_date']
        );
    }
}
