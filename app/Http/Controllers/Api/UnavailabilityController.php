<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnavailabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Unavailabilities\StoreUnavailabilityRequest;
use App\Http\Requests\Unavailabilities\UpdateUnavailabilityRequest;
use App\Http\Resources\UnavailabilityResource;
use App\Models\Unavailability;
use App\Services\Unavailabilities\UnavailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UnavailabilityController extends Controller
{
    public function __construct(
        private readonly UnavailabilityService $unavailabilityService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Unavailability::class);

        $user = request()->user();

        return UnavailabilityResource::collection(
            Unavailability::query()
                ->when(
                    ! $user->hasAnyRole(['admin', 'agent_communal']),
                    fn ($query) => $query->where('driver_id', $user->driver?->getKey() ?? 0)
                )
                ->orderBy('start_datetime')
                ->paginate()
        );
    }

    public function store(StoreUnavailabilityRequest $request): JsonResponse
    {
        try {
            $unavailability = $this->unavailabilityService->create(
                $request->validated(),
                $request->user()->id
            );
        } catch (UnavailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new UnavailabilityResource($unavailability))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Unavailability $unavailability): UnavailabilityResource
    {
        Gate::authorize('view', $unavailability);

        return new UnavailabilityResource($unavailability);
    }

    /** Met à jour une indisponibilité et enregistre l'historique des modifications
     * @throws UnavailabilityException
     */
    public function update(UpdateUnavailabilityRequest $request, Unavailability $unavailability): JsonResponse
    {
        $validated = $request->validated();
        $changeReason = $validated['change_reason'] ?? null;
        unset($validated['change_reason']);

        try {
            $unavailability = $this->unavailabilityService->update(
                $unavailability,
                $validated,
                $request->user()->id,
                $changeReason
            );
        } catch (UnavailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new UnavailabilityResource($unavailability))
            ->response()
            ->setStatusCode(200);
    }

    // On rend inactives les indisponibilites pour garder un historique complet
    public function cancel(Unavailability $unavailability): UnavailabilityResource
    {
        Gate::authorize('cancel', $unavailability);

        $unavailability = $this->unavailabilityService->cancel(
            $unavailability,
            request()->user()->id
        );

        return new UnavailabilityResource($unavailability);
    }
}
