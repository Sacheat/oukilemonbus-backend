<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GeocodingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Locations\StoreLocationRequest;
use App\Http\Requests\Locations\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\Locations\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function __construct(
        private readonly LocationService $locationService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return LocationResource::collection(
            Location::query()
                ->orderBy('city')
                ->orderBy('formatted_address')
                ->paginate()
        );
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        try {
            $location = $this->locationService->create($request->validated());
        } catch (GeocodingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return (new LocationResource($location))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location = $this->locationService->update($location, $request->validated());

        return new LocationResource($location);
    }
}
