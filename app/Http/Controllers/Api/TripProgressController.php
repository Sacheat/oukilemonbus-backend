<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DriverJourney\RecordTripEventRequest;
use App\Http\Resources\TripEventResource;
use App\Http\Resources\TripProgressResource;
use App\Http\Resources\TripReportResource;
use App\Models\Reservation;
use App\Services\DriverJourney\DriverJourneySettingsService;
use App\Services\Reservations\TripProgressService;
use App\Support\DevicePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Avancement des trajets (« Ma journée » du chauffeur, badges du Planning). Règles : TripProgressService.
 * Routes dédiées, sans toucher à ReservationController ni à ReservationResource.
 */
class TripProgressController extends Controller
{
    public function __construct(
        private readonly TripProgressService $tripProgressService,
        private readonly DriverJourneySettingsService $settings
    ) {}

    /** GET /trip-progress?reservation_ids[]=… (100 au plus) : avancement des trajets visibles */
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

        return TripProgressResource::collection(
            $this->tripProgressService->forReservations($validated['reservation_ids'], $request->user())
        );
    }

    /** GET /reservations/{reservation}/trip-progress : avancement, fenêtre du chauffeur et droit d'enregistrer */
    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->tripProgressService->ensureCanView($reservation, $request->user());

        $events = $reservation->tripEvents()->get();
        $window = $this->tripProgressService->driverWindow($reservation);
        $timezone = config('reservations.local_timezone', 'Europe/Brussels');
        $local = fn ($date): string => $date->setTimezone($timezone)->format('Y-m-d\TH:i:s');

        return response()->json([
            'data' => new TripProgressResource($this->tripProgressService->summary($events, $reservation->id)),
            'meta' => [
                // Heures locales de Bruxelles sans fuseau, comme les réservations
                'first_departure_opens_at' => $local($window['first_departure_opens_at']),
                'first_departure_closes_at' => $local($window['first_departure_closes_at']),
                'steps_close_at' => $local($window['steps_close_at']),
                'can_record' => $this->tripProgressService->canRecordNow($reservation, $request->user(), $events),
                // Agents et admins : lieu prévu et distance de chaque étape (carte du Planning)
                ...($this->tripProgressService->canManage($request->user()) ? [
                    'step_checks' => $this->tripProgressService->stepChecks($reservation, $events),
                    'step_distance_warning_meters' => $this->settings->stepDistanceWarningMeters(),
                ] : []),
            ],
        ]);
    }

    /**
     * DELETE /reservations/{reservation}/trip-events/last : annule la dernière étape (agents et admins, voir
     * TripProgressService::cancelLastStep). Renvoie l'étape annulée et le nouvel avancement.
     */
    public function destroyLast(Request $request, Reservation $reservation): JsonResponse
    {
        $result = $this->tripProgressService->cancelLastStep($reservation, $request->user());

        return response()->json([
            'data' => new TripEventResource($result['cancelled']),
            'meta' => ['progress' => new TripProgressResource($this->tripProgressService->summary($result['events'], $reservation->id))],
        ]);
    }

    /**
     * POST /reservations/{reservation}/trip-events : enregistre une étape. 201 si elle est nouvelle, 200 si elle l'était
     * déjà (double appui). Renvoie l'étape, l'avancement et le compte rendu créé à la dernière arrivée.
     */
    public function store(RecordTripEventRequest $request, Reservation $reservation): JsonResponse
    {
        $result = $this->tripProgressService->record(
            $reservation,
            $request->user(),
            TripEventType::from($request->validated('type')),
            DevicePosition::fromArray($request->validated('position')),
            $request->only(['actual_nb_children', 'actual_nb_adults'])
        );

        $events = $reservation->tripEvents()->get();

        return response()->json([
            'data' => new TripEventResource($result['event']),
            'meta' => [
                'created' => $result['created'],
                'progress' => new TripProgressResource($this->tripProgressService->summary($events, $reservation->id)),
                'trip_report' => $result['report'] ? new TripReportResource($result['report']) : null,
            ],
        ], $result['created'] ? 201 : 200);
    }
}
