<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Municipalities\StoreMunicipalityRequest;
use App\Http\Requests\Municipalities\SyncMunicipalityLocalitiesRequest;
use App\Http\Requests\Municipalities\UpdateMunicipalityRequest;
use App\Http\Resources\MunicipalityResource;
use App\Models\Municipality;
use App\Services\Municipalities\MunicipalitySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MunicipalityController extends Controller
{
    public function __construct(
        private readonly MunicipalitySettingsService $municipalitySettingsService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return MunicipalityResource::collection(
            Municipality::query()
                ->with('localities')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreMunicipalityRequest $request): JsonResponse
    {
        $municipality = $this->municipalitySettingsService->create($request->validated());

        return (new MunicipalityResource($municipality->load('localities')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Municipality $municipality): MunicipalityResource
    {
        return new MunicipalityResource($municipality->load('localities'));
    }

    public function update(UpdateMunicipalityRequest $request, Municipality $municipality): MunicipalityResource
    {
        $municipality = $this->municipalitySettingsService->update($municipality, $request->validated());

        return new MunicipalityResource($municipality->load('localities'));
    }

    public function syncLocalities(
        SyncMunicipalityLocalitiesRequest $request,
        Municipality $municipality
    ): MunicipalityResource {
        $municipality = $this->municipalitySettingsService->syncFromExternalProvider($municipality);

        return new MunicipalityResource($municipality->load('localities'));
    }
}
