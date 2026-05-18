<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\PlanningIndexRequest;
use App\Http\Resources\PlanningItemResource;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;

class PlanningController extends Controller
{
    public function __construct(
        private readonly PlanningService $planningService
    ) {}

    public function index(PlanningIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $includeAvailability = $request->boolean('include_availability')
            && $user->hasAnyRole(['admin', 'agent_communal']);

        $items = $this->planningService->forPeriod(
            $user,
            $validated['start_date'],
            $validated['end_date'],
            $includeAvailability,
            $validated['statuses'] ?? []
        );

        return response()->json([
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'data' => PlanningItemResource::collection($items)->resolve($request),
        ]);
    }
}
