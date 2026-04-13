<?php

namespace App\Http\Controllers;

use App\Exceptions\GeocodingException;
use App\Http\Requests\StoreLocationRequest;
use App\Models\Location;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function __construct(
        private LocationService $locationService
    ) {}

    public function index(): JsonResponse
    {
        $locations = Location::orderBy('city')->get();

        return response()->json($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $geocoded = $this->locationService->geocode(
                $validated['formatted_address']
            );

            $location = Location::create(array_merge($validated, $geocoded));

            return response()->json($location, 201);

        } catch (GeocodingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(string $id): JsonResponse
    {
        $location = Location::findOrFail($id);

        return response()->json($location);
    }
}
