<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ApproveReservationRequest;
use App\Http\Requests\Reservations\AssignReservationRequest;
use App\Http\Requests\Reservations\CancelReservationRequest;
use App\Http\Requests\Reservations\ConfirmReservationRequest;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationPreviewService $previewService,
        private readonly ReservationAssignmentService $assignmentService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Reservation::class);

        $user = request()->user();

        return ReservationResource::collection(
            Reservation::query()
                ->with(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts'])
                ->when(! $user->hasAnyRole(['admin', 'agent_communal']), function ($query) use ($user): void {
                    $query->where(function ($query) use ($user): void {
                        $query->where('requested_by_user_id', $user->getKey())
                            ->orWhereHas('organization.users', fn ($query) => $query->whereKey($user->getKey()))
                            ->when(
                                $user->hasRole('chauffeur') && $user->driver !== null,
                                fn ($query) => $query->orWhere('driver_id', $user->driver->getKey())
                            );
                    });
                })
                ->when(request('status'), fn ($query, $status) => $query->where('status', $status))
                ->when(request('organization_id'), fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
                ->when(request('date_from'), fn ($query, $date) => $query->whereDate('trip_date', '>=', $date))
                ->when(request('date_to'), fn ($query, $date) => $query->whereDate('trip_date', '<=', $date))
                ->orderByDesc('trip_date')
                ->orderBy('departure_time')
                ->paginate()
        );
    }

    public function preview(PreviewReservationRequest $request): JsonResponse
    {
        Gate::authorize('createForOrganization', [Reservation::class, Organization::findOrFail($request->validated('organization_id'))]);

        $preview = $this->previewService->preview($request->validated());

        return response()->json(['data' => $preview]);
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
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

    public function cancel(CancelReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->cancel($reservation, $request->user(), $request->validated('reason'));

        return new ReservationResource($reservation);
    }

    public function assign(AssignReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->assignmentService->assign($reservation, $request->validated(), $request->user());

        return new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));
    }

    public function confirm(ConfirmReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $reservation = $this->reservationService->confirm($reservation, $request->user());

        return new ReservationResource($reservation->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']));
    }
}
