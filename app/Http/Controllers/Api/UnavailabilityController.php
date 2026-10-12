<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnavailabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Unavailabilities\ListUnavailabilitiesRequest;
use App\Http\Requests\Unavailabilities\StoreUnavailabilityRequest;
use App\Http\Requests\Unavailabilities\UpdateUnavailabilityRequest;
use App\Http\Resources\UnavailabilityResource;
use App\Models\Unavailability;
use App\Services\Unavailabilities\UnavailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère les indisponibilités des chauffeurs et véhicules.
 * Les chauffeurs ne voient que leurs propres indisponibilités.
 * Les annulations ne suppriment pas la ligne : elles la marquent inactive pour conserver l'historique.
 */
class UnavailabilityController extends Controller
{
    public function __construct(
        private readonly UnavailabilityService $unavailabilityService
    ) {}

    /**
     * GET /unavailabilities : filtrée, triée et paginée par le backend (ListUnavailabilitiesRequest).
     *   period=a_venir → la plus proche d'abord ; passees, annulees → la plus récente d'abord ;
     *   sans period    → toutes, triées par début (comme avant).
     * Avec `period`, meta.period_counts donne le nombre de chaque onglet, avec les mêmes filtres chauffeur / véhicule.
     */
    public function index(ListUnavailabilitiesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Unavailability::class);

        $user = $request->user();
        $period = $request->validated('period');

        // Ce que l'utilisateur peut voir, avec les filtres chauffeur / véhicule (base commune à la liste et aux nombres)
        $visible = fn () => Unavailability::query()
            ->when(
                ! $user->hasAnyRole(['admin', 'agent_communal']),
                // Le chauffeur ne voit que ses indisponibilités ; le fallback à 0 évite
                // qu'un utilisateur sans profil chauffeur ne voie toutes les indisponibilités sans driver_id.
                fn ($query) => $query->where('driver_id', $user->driver?->getKey() ?? 0)
            )
            ->when($request->validated('driver_id'), fn ($query, $driverId) => $query->where('driver_id', $driverId))
            ->when($request->validated('vehicle_id'), fn ($query, $vehicleId) => $query->where('vehicle_id', $vehicleId));

        $page = $visible()
            // current=1 : seulement celles qui s'appliquent maintenant (état du jour des véhicules et chauffeurs)
            ->when($request->boolean('current'), fn ($query) => $query->currentlyActive())
            ->when($period, fn ($query) => $query->forPeriod($period))
            ->with(['driver.user', 'vehicle'])
            ->orderBy('start_datetime', $period === null || $period === 'a_venir' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();

        $collection = UnavailabilityResource::collection($page);

        if ($period !== null) {
            $collection->additional(['meta' => ['period_counts' => collect(Unavailability::PERIODS)
                ->mapWithKeys(fn (string $tab): array => [$tab => $visible()->forPeriod($tab)->count()])
                ->all()]]);
        }

        return $collection;
    }

    public function store(StoreUnavailabilityRequest $request): JsonResponse
    {
        try {
            $unavailability = $this->unavailabilityService->create(
                $request->validated(),
                $request->user()
            );
        } catch (UnavailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new UnavailabilityResource($unavailability->load(['driver.user', 'vehicle'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Unavailability $unavailability): UnavailabilityResource
    {
        Gate::authorize('view', $unavailability);

        return new UnavailabilityResource($unavailability->load(['driver.user', 'vehicle']));
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
                $request->user(),
                $changeReason
            );
        } catch (UnavailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new UnavailabilityResource($unavailability->load(['driver.user', 'vehicle'])))
            ->response()
            ->setStatusCode(200);
    }

    // On rend inactives les indisponibilites pour garder un historique complet
    public function cancel(Unavailability $unavailability): UnavailabilityResource
    {
        Gate::authorize('cancel', $unavailability);

        $unavailability = $this->unavailabilityService->cancel(
            $unavailability,
            request()->user()
        );

        return new UnavailabilityResource($unavailability->load(['driver.user', 'vehicle']));
    }
}
