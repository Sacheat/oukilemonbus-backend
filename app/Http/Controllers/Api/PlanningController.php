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

/**
 * Expose le planning opérationnel du bus communal.
 * Deux modes d'export coexistent :
 * - export() : synchrone, retourne le fichier directement (compatible Excel) ;
 * - requestExport() : asynchrone (202), génère un document en arrière-plan consultable via showExport().
 */
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

        // Les disponibilités détaillées (chauffeurs/véhicules libres) sont réservées aux rôles internes.
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

    /** Export synchrone : retourne le fichier Excel directement dans la réponse HTTP. */
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

    /**
     * Export asynchrone : enregistre la demande et retourne 202 Accepted.
     * Le document est généré en arrière-plan ; le frontend poll showExport() pour suivre l'avancement.
     */
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

    /** Consulte l'état d'une demande d'export asynchrone. */
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
            'id'             => $request->id,
            'status'         => $request->status,
            'start_date'     => $request->start_date?->toDateString(),
            'end_date'       => $request->end_date?->toDateString(),
            'statuses'       => $request->statuses ?? [],
            'filters'        => $request->filters ?? [],
            'document_id'    => $request->document_id,
            'requested_at'   => $request->requested_at?->toISOString(),
            'started_at'     => $request->started_at?->toISOString(),
            'completed_at'   => $request->completed_at?->toISOString(),
            'failed_at'      => $request->failed_at?->toISOString(),
            'error_message'  => $request->error_message,
        ];
    }
}
