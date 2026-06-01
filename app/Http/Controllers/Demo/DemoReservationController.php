<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\Reservations\ReservationAssignmentService;
use App\Services\Reservations\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rules\Enum;

final class DemoReservationController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly ReservationService $reservationService,
        private readonly ReservationAssignmentService $assignmentService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        return view('demo.reservations.index', [
            ...$this->shared($request),
            'reservations' => $this->demoData->reservations($actor, $request->only(['status', 'organization_id', 'date_from', 'date_to'])),
            'organizations' => $this->visibleOrganizations($actor),
            'drivers' => Driver::query()->with('user')->where('is_active', true)->orderBy('id')->get(),
            'vehicles' => Vehicle::query()->where('is_active', true)->orderBy('id')->get(),
            'statuses' => ReservationStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'create', Reservation::class);

        return view('demo.reservations.create', [
            ...$this->shared($request),
            'organizations' => $this->visibleOrganizations($actor),
            'locations' => Location::query()->orderBy('city')->orderBy('formatted_address')->get(),
            'activityTypes' => ReservationActivityType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $this->reservationPayload($request);
        $organization = Organization::query()->findOrFail($validated['organization_id']);

        return $this->runDemoAction(function () use ($actor, $validated, $organization): void {
            $this->authorizeFor($actor, 'createForOrganization', [Reservation::class, $organization]);
            $this->reservationService->create($validated, $actor);
        }, 'Reservation creee.');
    }

    public function edit(Request $request, Reservation $reservation): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'update', $reservation);

        return view('demo.reservations.edit', [
            ...$this->shared($request),
            'reservation' => $reservation->load(['organization', 'pickupLocation', 'destinationLocation']),
            'locations' => Location::query()->orderBy('city')->orderBy('formatted_address')->get(),
            'activityTypes' => ReservationActivityType::cases(),
        ]);
    }

    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'pickup_location_id' => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id', 'different:pickup_location_id'],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['required', 'date_format:H:i'],
            'return_time' => ['required', 'date_format:H:i', 'after:departure_time'],
            'nb_children' => ['required', 'integer', 'min:0'],
            'nb_adults' => ['required', 'integer', 'min:1'],
            'trip_purpose' => ['required', 'string', 'max:500'],
            'activity_type' => ['nullable', new Enum(ReservationActivityType::class)],
        ]);

        return $this->runDemoAction(function () use ($actor, $reservation, $validated): void {
            $this->authorizeFor($actor, 'update', $reservation);
            $this->reservationService->update($reservation, $validated, $actor);
        }, 'Reservation modifiee.');
    }

    public function autoAssign(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(function () use ($actor, $reservation): void {
            $this->authorizeFor($actor, 'assign', $reservation);
            $this->assignmentService->autoAssign($reservation, $actor);
        }, 'Affectation automatique effectuee.');
    }

    public function assign(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->runDemoAction(function () use ($actor, $reservation, $validated): void {
            $this->authorizeFor($actor, 'assign', $reservation);
            $this->assignmentService->assign($reservation, array_filter($validated, fn ($value) => $value !== null && $value !== ''), $actor);
        }, 'Affectation mise a jour.');
    }

    public function approve(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->runDemoAction(function () use ($actor, $reservation, $validated): void {
            $this->authorizeFor($actor, 'approve', $reservation);
            $this->reservationService->approve($reservation, $actor, $validated['reason'] ?? 'Validation depuis la demo backend');
        }, 'Reservation approuvee.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runDemoAction(function () use ($actor, $reservation, $validated): void {
            $this->authorizeFor($actor, 'reject', $reservation);
            $this->reservationService->reject($reservation, $actor, $validated['reason']);
        }, 'Reservation refusee.');
    }

    public function confirm(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(function () use ($actor, $reservation): void {
            $this->authorizeFor($actor, 'confirm', $reservation);
            $this->reservationService->confirm($reservation, $actor);
        }, 'Reservation confirmee.');
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runDemoAction(function () use ($actor, $reservation, $validated): void {
            $this->authorizeFor($actor, 'cancel', $reservation);
            $this->reservationService->cancel($reservation, $actor, $validated['reason']);
        }, 'Reservation annulee.');
    }

    private function reservationPayload(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'pickup_location_id' => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id', 'different:pickup_location_id'],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['required', 'date_format:H:i'],
            'return_time' => ['required', 'date_format:H:i', 'after:departure_time'],
            'nb_children' => ['required', 'integer', 'min:0'],
            'nb_adults' => ['required', 'integer', 'min:1'],
            'trip_purpose' => ['required', 'string', 'max:500'],
            'activity_type' => ['nullable', new Enum(ReservationActivityType::class)],
        ]);
    }

    private function visibleOrganizations($actor)
    {
        return Organization::query()
            ->when(
                ! $actor->hasAnyRole(['admin', 'agent_communal']),
                fn ($query) => $query->whereHas('users', fn ($query) => $query->whereKey($actor->getKey()))
            )
            ->orderBy('name')
            ->get();
    }
}
