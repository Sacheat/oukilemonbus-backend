<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\PlanningIndexRequest;
use App\Http\Resources\PlanningItemResource;
use App\Models\PlanningExportRequest;
use App\Services\Planning\PlanningAsyncExportService;
use App\Services\Planning\PlanningExportService;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PlanningController extends Controller
{
    public function __construct(
        private readonly PlanningService $planningService,
        private readonly PlanningExportService $planningExportService,
        private readonly PlanningAsyncExportService $planningAsyncExportService
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

    public function requestExport(PlanningIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $exportRequest = $this->planningAsyncExportService->requestExport(
            user: $request->user(),
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            statuses: $request->planningStatuses(),
            filters: $request->planningFilters()
        );

        return response()->json([
            'data' => $this->exportPayload($exportRequest),
        ], 202);
    }

    public function showExport(PlanningExportRequest $planningExportRequest): JsonResponse
    {
        $exportRequest = $this->planningAsyncExportService->getForUser(
            user: request()->user(),
            id: $planningExportRequest->id
        );

        return response()->json([
            'data' => $this->exportPayload($exportRequest),
        ]);
    }

    private function exportPayload(PlanningExportRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'start_date' => $request->start_date?->toDateString(),
            'end_date' => $request->end_date?->toDateString(),
            'statuses' => $request->statuses ?? [],
            'filters' => $request->filters ?? [],
            'document_id' => $request->document_id,
            'requested_at' => $request->requested_at?->toISOString(),
            'started_at' => $request->started_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'failed_at' => $request->failed_at?->toISOString(),
            'error_message' => $request->error_message,
        ];
    }
}
