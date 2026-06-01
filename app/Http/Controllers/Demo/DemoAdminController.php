<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Models\Driver;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemoAdminController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
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

        return view('demo.admin.index', [
            ...$this->shared($request),
            'users' => $actor->can('users.view')
                ? User::query()->with(['roles', 'organizations'])->latest()->limit(20)->get()
                : collect(),
            'drivers' => $actor->can('drivers.view')
                ? Driver::query()->with('user')->orderBy('id')->limit(20)->get()
                : collect(),
            'vehicles' => $actor->can('vehicles.view')
                ? Vehicle::query()->orderBy('id')->limit(20)->get()
                : collect(),
            'unavailabilities' => $actor->can('unavailabilities.view')
                ? $this->demoData->unavailabilities($actor)
                : null,
            'canViewUsers' => $actor->can('users.view'),
            'canViewDrivers' => $actor->can('drivers.view'),
            'canViewVehicles' => $actor->can('vehicles.view'),
            'canViewUnavailabilities' => $actor->can('unavailabilities.view'),
        ]);
    }
}
