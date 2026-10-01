<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RouteSheetResource;
use App\Services\Documents\DriverRouteSheetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Feuilles de route pour l'espace chauffeur (règles : DriverRouteSheetService).
 * Routes dédiées, sans toucher à DocumentController (GET /documents reste inchangé).
 */
class RouteSheetController extends Controller
{
    public function __construct(
        private readonly DriverRouteSheetService $driverRouteSheetService
    ) {}

    /**
     * GET /route-sheets?date_from=&date_to=&page= : feuilles officielles visibles, dernière version, 15 par page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(
            [
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ],
            [
                'date_from.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
                'date_to.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
                'date_to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]
        );

        return RouteSheetResource::collection(
            $this->driverRouteSheetService->officialSheets(
                $request->user(),
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            )
        );
    }

    /**
     * GET /route-sheets/driver?week=AAAA-MM-JJ[&driver_id=] : PDF de la semaine (lundi → dimanche contenant la date)
     * du chauffeur, généré à la demande.
     */
    public function driverWeek(Request $request): Response
    {
        $validated = $request->validate(
            [
                'week' => ['required', 'date_format:Y-m-d'],
                'driver_id' => ['nullable', 'integer'],
            ],
            [
                'week.required' => 'Indiquez la semaine.',
                'week.date_format' => 'La semaine doit être une date au format AAAA-MM-JJ.',
            ]
        );

        $driver = $this->driverRouteSheetService->resolveDriver(
            $request->user(),
            isset($validated['driver_id']) ? (int) $validated['driver_id'] : null
        );
        $sheet = $this->driverRouteSheetService->renderWeek($driver, $validated['week']);

        return response($sheet['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$sheet['file_name'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
