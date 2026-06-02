<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\CouncilDecisionStatus;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\CouncilDecision;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Services\CouncilDecisions\CouncilDecisionService;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\Reservations\ReservationAssignmentService;
use App\Services\Reservations\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class DemoReservationController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly ReservationService $reservationService,
        private readonly ReservationAssignmentService $assignmentService,
        private readonly CouncilDecisionService $councilDecisionService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        $reservations = $this->demoData->reservations(
            $actor,
            $request->only(['status', 'organization_id', 'date_from', 'date_to'])
        );

        $organizations = $this->visibleOrganizations($actor);
        $drivers = Driver::query()->with('user')->where('is_active', true)->orderBy('id')->get();
        $vehicles = Vehicle::query()->where('is_active', true)->orderBy('id')->get();

        $this->recordInspectorView($request, 'reservations.index', [
            'status' => 'success',
            'message' => 'Ouverture de la liste des réservations.',
            'actor_id' => $actor->id,
            'reservations_count' => method_exists($reservations, 'total') ? $reservations->total() : $reservations->count(),
            'organizations_count' => $organizations->count(),
            'drivers_count' => $drivers->count(),
            'vehicles_count' => $vehicles->count(),
            'filters' => $request->only(['status', 'organization_id', 'date_from', 'date_to']),
        ]);

        return view('demo.reservations.index', [
            ...$this->shared($request),
            'reservations' => $reservations,
            'organizations' => $organizations,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
            'statuses' => ReservationStatus::cases(),
            'councilDecisionStatuses' => [
                CouncilDecisionStatus::APPROUVE,
                CouncilDecisionStatus::REFUSE,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'create', Reservation::class);

        $this->recordInspectorView($request, 'reservations.create', [
            'status' => 'success',
            'message' => 'Ouverture du formulaire de création d’une réservation.',
            'actor_id' => $actor->id,
        ]);

        return view('demo.reservations.create', [
            ...$this->shared($request),
            'organizations' => $this->visibleOrganizations($actor),
            'locations' => Location::query()
                ->orderBy('city')
                ->orderBy('formatted_address')
                ->get(),
            'activityTypes' => ReservationActivityType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $this->reservationPayload($request);
        $organization = Organization::query()->findOrFail($validated['organization_id']);

        return $this->runDemoAction(
            function () use ($actor, $validated, $organization): void {
                $this->authorizeFor($actor, 'createForOrganization', [Reservation::class, $organization]);
                $this->reservationService->create($validated, $actor);
            },
            'Reservation creee.',
            $request,
            'reservations.store',
            [
                'actor_id' => $actor->id,
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'pickup_location_id' => $validated['pickup_location_id'] ?? null,
                'destination_location_id' => $validated['destination_location_id'] ?? null,
                'trip_date' => $validated['trip_date'] ?? null,
                'departure_time' => $validated['departure_time'] ?? null,
                'return_time' => $validated['return_time'] ?? null,
                'nb_children' => $validated['nb_children'] ?? null,
                'nb_adults' => $validated['nb_adults'] ?? null,
                'activity_type' => $validated['activity_type'] ?? null,
                'validated_fields' => array_keys($validated),
            ]
        );
    }

    public function edit(Request $request, Reservation $reservation): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'update', $reservation);

        $this->recordInspectorView($request, 'reservations.edit', [
            'status' => 'success',
            'message' => 'Ouverture du formulaire de modification d’une réservation.',
            'actor_id' => $actor->id,
            'reservation_id' => $reservation->id,
            'organization_id' => $reservation->organization_id,
            'reservation_status' => $reservation->status?->value ?? $reservation->status,
        ]);

        return view('demo.reservations.edit', [
            ...$this->shared($request),
            'reservation' => $reservation,
            'locations' => Location::query()
                ->orderBy('city')
                ->orderBy('formatted_address')
                ->get(),
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

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $this->authorizeFor($actor, 'update', $reservation);
                $this->reservationService->update($reservation, $validated, $actor);
            },
            'Reservation mise a jour.',
            $request,
            'reservations.update',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id_before' => $reservation->driver_id,
                'vehicle_id_before' => $reservation->vehicle_id,
                'updated_fields' => array_keys($validated),
            ]
        );
    }

    public function autoAssign(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(
            function () use ($actor, $reservation): void {
                $this->authorizeFor($actor, 'assign', $reservation);
                $this->assignmentService->autoAssign($reservation, $actor);
            },
            'Affectation automatique effectuee.',
            $request,
            'reservations.auto_assign',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id_before' => $reservation->driver_id,
                'vehicle_id_before' => $reservation->vehicle_id,
            ]
        );
    }

    public function assign(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $this->authorizeFor($actor, 'assign', $reservation);
                $this->assignmentService->assign($reservation, $validated, $actor);
            },
            'Affectation mise a jour.',
            $request,
            'reservations.assign',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id_before' => $reservation->driver_id,
                'vehicle_id_before' => $reservation->vehicle_id,
                'driver_id_requested' => $validated['driver_id'] ?? null,
                'vehicle_id_requested' => $validated['vehicle_id'] ?? null,
            ]
        );
    }

    public function councilDecision(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'decision_status' => [
                'required',
                new Enum(CouncilDecisionStatus::class),
                Rule::in([CouncilDecisionStatus::APPROUVE->value, CouncilDecisionStatus::REFUSE->value]),
            ],
            'decision_date' => ['nullable', 'date'],
            'decision_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $hasExistingDecision = $reservation->councilDecision()->exists();

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $reservation->loadMissing(['organization', 'councilDecision']);

                $payload = [
                    'reservation_id' => $reservation->id,
                    'decision_status' => $validated['decision_status'],
                    'decision_date' => $validated['decision_date'] ?? now()->toDateString(),
                    'decision_reference' => ($validated['decision_reference'] ?? null) ?: $this->decisionReference($reservation),
                    'notes' => $validated['notes'] ?? 'Decision encodee depuis la demo',
                ];

                if ($reservation->councilDecision instanceof CouncilDecision) {
                    $this->authorizeFor($actor, 'update', $reservation->councilDecision);
                    $this->councilDecisionService->update($reservation->councilDecision, $payload, $actor);

                    return;
                }

                $this->authorizeFor($actor, 'create', [CouncilDecision::class, $reservation]);
                $this->councilDecisionService->create($payload, $actor);
            },
            'Decision du college encodee.',
            $request,
            'reservations.council_decision',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'operation' => $hasExistingDecision ? 'update' : 'create',
                'decision_status' => $validated['decision_status'],
                'decision_date' => $validated['decision_date'] ?? now()->toDateString(),
                'decision_reference' => ($validated['decision_reference'] ?? null) ?: $this->decisionReference($reservation),
            ]
        );
    }

    public function approve(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $this->authorizeFor($actor, 'approve', $reservation);
                $this->reservationService->approve(
                    $reservation,
                    $actor,
                    $validated['reason'] ?? 'Validation depuis la demo backend'
                );
            },
            'Reservation approuvee.',
            $request,
            'reservations.approve',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id' => $reservation->driver_id,
                'vehicle_id' => $reservation->vehicle_id,
                'has_council_decision' => $reservation->councilDecision()->exists(),
                'reason' => $validated['reason'] ?? 'Validation depuis la demo backend',
            ]
        );
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $this->authorizeFor($actor, 'reject', $reservation);
                $this->reservationService->reject($reservation, $actor, $validated['reason']);
            },
            'Reservation refusee.',
            $request,
            'reservations.reject',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id' => $reservation->driver_id,
                'vehicle_id' => $reservation->vehicle_id,
                'reason' => $validated['reason'],
            ]
        );
    }

    public function confirm(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(
            function () use ($actor, $reservation): void {
                $this->authorizeFor($actor, 'confirm', $reservation);
                $this->reservationService->confirm($reservation, $actor);
            },
            'Reservation confirmee.',
            $request,
            'reservations.confirm',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id' => $reservation->driver_id,
                'vehicle_id' => $reservation->vehicle_id,
            ]
        );
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runDemoAction(
            function () use ($actor, $reservation, $validated): void {
                $this->authorizeFor($actor, 'cancel', $reservation);
                $this->reservationService->cancel($reservation, $actor, $validated['reason']);
            },
            'Reservation annulee.',
            $request,
            'reservations.cancel',
            [
                'actor_id' => $actor->id,
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
                'status_before' => $reservation->status?->value ?? $reservation->status,
                'driver_id' => $reservation->driver_id,
                'vehicle_id' => $reservation->vehicle_id,
                'reason' => $validated['reason'],
            ]
        );
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

    private function decisionReference(Reservation $reservation): string
    {
        return sprintf('DEMO-%s-R%04d', now()->format('Ymd'), $reservation->id);
    }
}
