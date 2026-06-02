<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\UnavailabilityReasonType;
use App\Models\Driver;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\Unavailabilities\UnavailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DemoAdminController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly UnavailabilityService $unavailabilityService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        abort_unless(
            $actor->can('users.view')
            || $actor->can('drivers.view')
            || $actor->can('vehicles.view')
            || $actor->can('unavailabilities.view'),
            403
        );

        $users = $actor->can('users.view')
            ? User::query()->with(['roles', 'organizations'])->latest()->limit(20)->get()
            : collect();

        $drivers = $actor->can('drivers.view')
            ? Driver::query()->with('user')->orderBy('id')->limit(20)->get()
            : collect();

        $vehicles = $actor->can('vehicles.view')
            ? Vehicle::query()->orderBy('id')->limit(20)->get()
            : collect();

        $unavailabilities = $actor->can('unavailabilities.view')
            ? $this->demoData->unavailabilities($actor)
            : null;

        $this->recordInspectorView($request, 'admin.index', [
            'status' => 'success',
            'message' => 'Ouverture de la page de gestion.',
            'actor_id' => $actor->id,
            'users_count' => $users->count(),
            'drivers_count' => $drivers->count(),
            'vehicles_count' => $vehicles->count(),
            'can_view_users' => $actor->can('users.view'),
            'can_view_drivers' => $actor->can('drivers.view'),
            'can_view_vehicles' => $actor->can('vehicles.view'),
            'can_view_unavailabilities' => $actor->can('unavailabilities.view'),
        ]);

        return view('demo.admin.index', [
            ...$this->shared($request),
            'users' => $users,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
            'unavailabilities' => $unavailabilities,
            'canViewUsers' => $actor->can('users.view'),
            'canViewDrivers' => $actor->can('drivers.view'),
            'canViewVehicles' => $actor->can('vehicles.view'),
            'canViewUnavailabilities' => $actor->can('unavailabilities.view'),
            'canCreateUnavailability' => $actor->can('unavailabilities.create'),
            'reasonTypes' => UnavailabilityReasonType::cases(),
        ]);
    }

    public function storeUnavailability(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'reason_type' => ['required', Rule::enum(UnavailabilityReasonType::class)],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->runDemoAction(function () use ($actor, $validated): void {
            $this->authorizeFor($actor, 'create', Unavailability::class);
            $this->unavailabilityService->create($validated, $actor->id);
        }, 'Indisponibilite creee.');
    }

    public function cancelUnavailability(Request $request, Unavailability $unavailability): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(function () use ($actor, $unavailability): void {
            $this->authorizeFor($actor, 'cancel', $unavailability);
            $this->unavailabilityService->cancel($unavailability, $actor->id);
        }, 'Indisponibilite annulee.');
    }
}
