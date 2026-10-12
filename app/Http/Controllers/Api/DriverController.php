<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\StoreDriverRequest;
use App\Http\Requests\Drivers\UpdateDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Services\Drivers\DriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD chauffeurs.
 * `activate`/`deactivate` gèrent le statut opérationnel sans supprimer le profil.
 * Les chauffeurs sont triés par date d'expiration de permis pour identifier rapidement les expirations proches.
 */
class DriverController extends Controller
{
    public function __construct(
        private readonly DriverService $driverService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return DriverResource::collection(
            Driver::query()
                ->with('user')
                // active=1 : seulement les chauffeurs actifs (listes de choix : la flotte garde les anciens pour l'historique)
                ->when(request()->boolean('active'), fn ($query) => $query->where('is_active', true))
                ->orderBy('licence_expiry_date')
                ->paginate()
        );
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $driver = $this->driverService->create($request->validated());

        return (new DriverResource($driver))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Driver $driver): DriverResource
    {
        return new DriverResource($driver);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): DriverResource
    {
        $driver = $this->driverService->update($driver, $request->validated());

        return new DriverResource($driver);
    }

    public function activate(Driver $driver): DriverResource
    {
        $driver = $this->driverService->activate($driver);

        return new DriverResource($driver->load('user'));
    }

    public function deactivate(Driver $driver): DriverResource
    {
        $driver = $this->driverService->deactivate($driver);

        return new DriverResource($driver->load('user'));
    }
}
