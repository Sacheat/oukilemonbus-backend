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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gestion des lieux (géocodage et autocomplétion).
 * `store` et `update` (si l'adresse change) appellent LocationService qui déclenche le géocodage ORS — GeocodingException → 422.
 * `suggest` requiert au minimum 3 caractères avant d'interroger l'API pour éviter les appels inutiles.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly LocationService $locationService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LocationResource::collection(
            Location::query()
                ->when($request->filled('q'), function ($query) use ($request): void {
                    $term = '%'.$request->string('q').'%';
                    $query->where(function ($q) use ($term): void {
                        $q->where('formatted_address', 'like', $term)
                          ->orWhere('city', 'like', $term);
                    });
                })
                ->orderBy('city')
                ->orderBy('formatted_address')
                ->paginate(10)
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

    public function suggest(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim();

        if ($q->length() < 3) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $this->locationService->suggest((string) $q),
        ]);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource|JsonResponse
    {
        try {
            $location = $this->locationService->update($location, $request->validated());
        } catch (GeocodingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return new LocationResource($location);
    }
}
