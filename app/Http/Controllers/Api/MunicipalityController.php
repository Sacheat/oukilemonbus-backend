<?php

namespace App\Http\Controllers\Api;

use App\Services\Planning\WeeklyPlanningService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Municipalities\StoreMunicipalityRequest;
use App\Http\Requests\Municipalities\SyncMunicipalityLocalitiesRequest;
use App\Http\Requests\Municipalities\UpdateMunicipalityRequest;
use App\Http\Resources\MunicipalityResource;
use App\Models\Municipality;
use App\Services\Municipalities\MunicipalitySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD municipalités et synchronisation des localités.
 * L'index trie par `is_active` DESC pour que les municipalités actives apparaissent en premier.
 * `syncLocalities` interroge un fournisseur externe pour mettre à jour les localités rattachées.
 */
class MunicipalityController extends Controller
{
    public function __construct(
        private readonly MunicipalitySettingsService $municipalitySettingsService,
        private readonly WeeklyPlanningService $weeklyPlanningService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return MunicipalityResource::collection(
            Municipality::query()
                ->with(['localities', 'depotLocation', 'weeklyPlanningOwner.roles'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreMunicipalityRequest $request): JsonResponse
    {
        $municipality = $this->municipalitySettingsService->create($request->validated());

        return (new MunicipalityResource($municipality->load(['localities', 'depotLocation', 'weeklyPlanningOwner.roles'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Municipality $municipality): MunicipalityResource
    {
        return new MunicipalityResource($municipality->load(['localities', 'depotLocation', 'weeklyPlanningOwner.roles']));
    }

    public function update(UpdateMunicipalityRequest $request, Municipality $municipality): MunicipalityResource
    {
        $previousOwnerId = $municipality->weekly_planning_owner_user_id;
        $municipality = $this->municipalitySettingsService->update($municipality, $request->validated());

        // Responsable du planning changé : historique et personnes prévenues
        if ($request->has('weekly_planning_owner_user_id')) {
            $this->weeklyPlanningService->ownerChanged($municipality, $previousOwnerId, $request->user());
        }

        return new MunicipalityResource($municipality->load(['localities', 'depotLocation', 'weeklyPlanningOwner.roles']));
    }

    public function syncLocalities(
        SyncMunicipalityLocalitiesRequest $request,
        Municipality $municipality
    ): MunicipalityResource {
        $municipality = $this->municipalitySettingsService->syncFromExternalProvider($municipality);

        return new MunicipalityResource($municipality->load(['localities', 'depotLocation', 'weeklyPlanningOwner.roles']));
    }
}
