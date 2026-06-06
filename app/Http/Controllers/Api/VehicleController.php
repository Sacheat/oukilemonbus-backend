<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehicleRequest;
use App\Http\Requests\Vehicles\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\Vehicles\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD véhicules.
 * `activate`/`deactivate` gèrent le statut opérationnel sans supprimer le véhicule.
 * Un véhicule inactif ne peut pas être assigné à une réservation.
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleService $vehicleService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return VehicleResource::collection(
            Vehicle::query()
                ->orderBy('licence_plate')
                ->paginate()
        );
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->vehicleService->create($request->validated());

        return (new VehicleResource($vehicle))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($vehicle);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->update($vehicle, $request->validated());

        return new VehicleResource($vehicle);
    }

    public function activate(Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->activate($vehicle);

        return new VehicleResource($vehicle);
    }

    public function deactivate(Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->deactivate($vehicle);

        return new VehicleResource($vehicle);
    }
}
