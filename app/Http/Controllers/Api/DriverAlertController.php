<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DriverJourney\CloseDriverAlertRequest;
use App\Http\Requests\DriverJourney\ListDriverAlertsRequest;
use App\Http\Requests\DriverJourney\StoreDriverAlertRequest;
use App\Http\Requests\DriverJourney\UpdateDriverAlertPositionRequest;
use App\Http\Resources\DriverAlertResource;
use App\Models\DriverAlert;
use App\Models\Reservation;
use App\Services\DriverAlerts\DriverAlertService;
use App\Services\Idempotency\ApiIdempotencyService;
use App\Support\DevicePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Demandes d'aide des chauffeurs (bouton « Aide »). Règles : DriverAlertService.
 * POST /driver-alerts accepte un header Idempotency-Key : un double appui ne crée pas deux alertes.
 */
class DriverAlertController extends Controller
{
    public function __construct(
        private readonly DriverAlertService $driverAlertService,
        private readonly ApiIdempotencyService $idempotencyService
    ) {}

    public function index(ListDriverAlertsRequest $request): AnonymousResourceCollection
    {
        return DriverAlertResource::collection($this->driverAlertService->list($request->user(), $request->validated()));
    }

    public function show(Request $request, DriverAlert $driverAlert): DriverAlertResource
    {
        $this->driverAlertService->ensureCanView($driverAlert, $request->user());

        return new DriverAlertResource($driverAlert->load($this->driverAlertService->relations()));
    }

    public function store(StoreDriverAlertRequest $request): JsonResponse
    {
        $create = function () use ($request): array {
            $reservationId = $request->validated('reservation_id');
            $alert = $this->driverAlertService->create(
                $request->user(),
                $reservationId ? Reservation::query()->findOrFail($reservationId) : null,
                DevicePosition::fromArray($request->validated('position')),
                $request->validated('message')
            );

            return ['status' => 201, 'body' => (new DriverAlertResource($alert))->response()->getData(true)];
        };

        $key = $request->header('Idempotency-Key');
        $response = is_string($key) && $key !== ''
            ? $this->idempotencyService->execute(
                user: $request->user(),
                key: $key,
                method: 'POST',
                routeName: (string) ($request->route()?->getName() ?? 'api.driver-alerts.store'),
                requestHash: hash('sha256', json_encode($request->validated(), JSON_THROW_ON_ERROR)),
                action: $create
            )
            : $create();

        return response()->json($response['body'], $response['status']);
    }

    public function updatePosition(UpdateDriverAlertPositionRequest $request, DriverAlert $driverAlert): DriverAlertResource
    {
        return new DriverAlertResource($this->driverAlertService->updatePosition(
            $driverAlert,
            $request->user(),
            DevicePosition::fromArray($request->validated('position'))
        ));
    }

    public function take(Request $request, DriverAlert $driverAlert): DriverAlertResource
    {
        return new DriverAlertResource($this->driverAlertService->take($driverAlert, $request->user()));
    }

    public function close(CloseDriverAlertRequest $request, DriverAlert $driverAlert): DriverAlertResource
    {
        return new DriverAlertResource(
            $this->driverAlertService->close($driverAlert, $request->user(), $request->validated('closing_note'))
        );
    }
}
