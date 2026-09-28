<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ApproveReservationRequest;
use App\Http\Requests\Reservations\AssignReservationRequest;
use App\Http\Requests\Reservations\CancelReservationRequest;
use App\Http\Requests\Reservations\CheckTripRequest;
use App\Http\Requests\Reservations\ConfirmPassengersRequest;
use App\Http\Requests\Reservations\RetryAutoAssignRequest;
use App\Http\Requests\Reservations\ConfirmReservationRequest;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Http\Requests\Reservations\PreviewReservationRequest;
use App\Http\Requests\Reservations\RejectReservationRequest;
use App\Http\Requests\Reservations\StoreReservationRequest;
use App\Http\Requests\Reservations\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Organization;
use App\Models\Reservation;
use App\Services\Reservations\ReservationAssignmentService;
use App\Services\Reservations\ReservationPreviewService;
use App\Services\Reservations\ReservationService;
use App\Services\Idempotency\ApiIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère le cycle de vie complet des réservations via l'API.
 * Les actions de mutation sensibles (create, cancel, assign) supportent une Idempotency-Key
 * pour éviter les doublons en cas de double soumission réseau côté frontend.
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationPreviewService $previewService,
        private readonly ReservationAssignmentService $assignmentService,
        private readonly ApiIdempotencyService $idempotencyService
    ) {}

    public function index(ListReservationsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Reservation::class);

        $user = $request->user();

        return ReservationResource::collection(
            Reservation::query()
                ->with(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts'])
                // Le scope visibleTo filtre selon le rôle : admin/agent voit tout ; les autres voient
                // leurs réservations et celles de leurs organisations, le chauffeur celles où il est assigné.
                ->visibleTo($user)
                ->when(request('status'), fn ($query, $status) => $query->where('status', $status))
                ->when(request('organization_id'), fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
                ->when(request('date_from'), fn ($query, $date) => $query->whereDate('trip_date', '>=', $date))
                ->when(request('date_to'), fn ($query, $date) => $query->whereDate('trip_date', '<=', $date))
                // sort=asc : trajet le plus proche d'abord (onglet « À venir ») ; desc par défaut.
                ->orderBy('trip_date', $request->sortDirection())
                ->orderBy('departure_time')
                ->paginate()
                ->withQueryString()
        );
    }

    public function preview(PreviewReservationRequest $request): JsonResponse
    {
        // Vérifie que l'utilisateur peut créer pour cette organisation avant de calculer le preview.
        Gate::authorize('createForOrganization', [Reservation::class, Organization::findOrFail($request->validated('organization_id'))]);

        $preview = $this->previewService->preview($request->validated());

        return response()->json(['data' => $preview]);
    }

    /**
     * Vérifie le trajet seul (étape 1 du formulaire) : mêmes règles et même réponse que preview(),
     * sans la capacité du véhicule. Ne crée ni ne modifie rien.
     */
    public function checkTrip(CheckTripRequest $request): JsonResponse
    {
        Gate::authorize('createForOrganization', [Reservation::class, Organization::findOrFail($request->validated('organization_id'))]);

        return response()->json(['data' => $this->previewService->checkTrip($request->validated())]);
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            // Si une Idempotency-Key est fournie, le service rejoue la réponse mise en cache
            // si la même clé est reçue une seconde fois, évitant la création d'un doublon.
            $response = $this->idempotencyService->execute(
                user: $request->user(),
                key: $idempotencyKey,
                method: 'POST',
                routeName: (string) ($request->route()?->getName() ?? 'api.reservations.store'),
                requestHash: hash('sha256', json_encode($request->validated(), JSON_THROW_ON_ERROR)),
                action: function () use ($request): array {
                    $reservation = $this->reservationService->create($request->validated(), $request->user());

                    return [
                        'status' => 201,
                        'body' => (new ReservationResource($reservation))->response()->getData(true),
                    ];
                }
            );

            return response()->json($response['body'], $response['status']);
        }

        $reservation = $this->reservationService->create($request->validated(), $request->user());

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Reservation $reservation): ReservationResource
    {
        Gate::authorize('view', $reservation);

        return new ReservationResource(
            $reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts'])
        );
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->update($reservation, $request->validated(), $request->user());

        return new ReservationResource($reservation);
    }

    public function approve(ApproveReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->approve($reservation, $request->user(), $request->validated('reason'));

        return new ReservationResource($reservation);
    }

    public function reject(RejectReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->reject($reservation, $request->user(), $request->validated('reason'));

        return new ReservationResource($reservation);
    }

    public function cancel(CancelReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $response = $this->idempotencyService->execute(
                user: $request->user(),
                key: $idempotencyKey,
                method: 'PATCH',
                routeName: (string) ($request->route()?->getName() ?? 'api.reservations.cancel'),
                requestHash: hash('sha256', json_encode($request->validated() + ['reservation_id' => $reservation->id], JSON_THROW_ON_ERROR)),
                action: function () use ($request, $reservation): array {
                    $updated = $this->reservationService->cancel($reservation, $request->user(), $request->validated('reason'));

                    return [
                        'status' => 200,
                        'body' => (new ReservationResource($updated))->response()->getData(true),
                    ];
                }
            );

            return response()->json($response['body'], $response['status']);
        }

        $reservation = $this->reservationService->cancel($reservation, $request->user(), $request->validated('reason'));

        return response()->json((new ReservationResource($reservation))->response()->getData(true));
    }

    public function assign(AssignReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $response = $this->idempotencyService->execute(
                user: $request->user(),
                key: $idempotencyKey,
                method: 'PATCH',
                routeName: (string) ($request->route()?->getName() ?? 'api.reservations.assign'),
                requestHash: hash('sha256', json_encode($request->validated() + ['reservation_id' => $reservation->id], JSON_THROW_ON_ERROR)),
                action: function () use ($request, $reservation): array {
                    $updated = $this->reservationService->assign($reservation, $request->validated(), $request->user());
                    $resource = new ReservationResource($updated->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));

                    return [
                        'status' => 200,
                        'body' => $resource->response()->getData(true),
                    ];
                }
            );

            return response()->json($response['body'], $response['status']);
        }

        $reservation = $this->reservationService->assign($reservation, $request->validated(), $request->user());

        $resource = new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));

        return response()->json($resource->response()->getData(true));
    }

    public function confirm(ConfirmReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->confirm($reservation, $request->user());

        return new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));
    }

    public function retryAutoAssign(RetryAutoAssignRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->retryAutoAssign($reservation, $request->user());

        return new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));
    }

    public function confirmPassengers(ConfirmPassengersRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->confirmPassengers($reservation, $request->validated(), $request->user());

        return new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));
    }
}
