<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\SaveTripReportRequest;
use App\Http\Resources\TripReportResource;
use App\Models\Reservation;
use App\Services\Reservations\TripReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Comptes rendus de trajet (chauffeur affecté ou agent / admin). Règles : TripReportService.
 * Routes dédiées, sans toucher à ReservationController ni à ReservationResource.
 */
class TripReportController extends Controller
{
    public function __construct(
        private readonly TripReportService $tripReportService
    ) {}

    /**
     * GET /trip-reports?reservation_ids[]=… (100 au plus) : comptes rendus visibles, pour les badges de listes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(
            [
                'reservation_ids' => ['required', 'array', 'min:1', 'max:100'],
                'reservation_ids.*' => ['integer'],
            ],
            [
                'reservation_ids.required' => 'Indiquez au moins une réservation.',
                'reservation_ids.max' => 'Au plus 100 réservations à la fois.',
            ]
        );

        return TripReportResource::collection(
            $this->tripReportService->forReservations($validated['reservation_ids'], $request->user())
        );
    }

    /**
     * GET /reservations/{reservation}/trip-report : le compte rendu (ou null) et la fenêtre du chauffeur.
     */
    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->tripReportService->ensureCanView($reservation, $request->user());

        $report = $reservation->tripReport()->with(['driver', 'reportedByUser'])->first();
        ['opens_at' => $opensAt, 'closes_at' => $closesAt] = $this->tripReportService->driverWindow($reservation);

        return response()->json([
            'data' => $report ? new TripReportResource($report) : null,
            'meta' => [
                // Heure locale de Bruxelles sans fuseau, comme les réservations
                'driver_window_opens_at' => $opensAt->format('Y-m-d\TH:i:s'),
                'driver_window_closes_at' => $closesAt->format('Y-m-d\TH:i:s'),
                'can_submit' => $this->tripReportService->canSubmitNow($reservation, $request->user()),
            ],
        ]);
    }

    /**
     * PUT /reservations/{reservation}/trip-report : crée ou met à jour le compte rendu.
     */
    public function update(SaveTripReportRequest $request, Reservation $reservation): TripReportResource
    {
        return new TripReportResource(
            $this->tripReportService->save($reservation, $request->validated(), $request->user())
        );
    }
}
