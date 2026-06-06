<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Municipalities\StoreMunicipalityLocalityRequest;
use App\Http\Requests\Municipalities\UpdateMunicipalityLocalityRequest;
use App\Http\Resources\MunicipalityLocalityResource;
use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use App\Services\Municipalities\MunicipalitySettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Gestion des localités d'une municipalité.
 * `deactivate` désactive sans supprimer pour conserver l'historique des rattachements passés.
 */
class MunicipalityLocalityController extends Controller
{
    public function __construct(
        private readonly MunicipalitySettingsService $municipalitySettingsService
    ) {}

    public function store(StoreMunicipalityLocalityRequest $request, Municipality $municipality): JsonResponse
    {
        $locality = $this->municipalitySettingsService->addLocality($municipality, $request->validated());

        return (new MunicipalityLocalityResource($locality))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateMunicipalityLocalityRequest $request,
        MunicipalityLocality $municipalityLocality
    ): MunicipalityLocalityResource {
        $locality = $this->municipalitySettingsService->updateLocality(
            $municipalityLocality,
            $request->validated()
        );

        return new MunicipalityLocalityResource($locality);
    }

    public function deactivate(MunicipalityLocality $municipalityLocality): MunicipalityLocalityResource
    {
        $locality = $this->municipalitySettingsService->deactivateLocality($municipalityLocality);

        return new MunicipalityLocalityResource($locality);
    }
}
