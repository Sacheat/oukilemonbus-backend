<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\BanOrganizationRequest;
use App\Http\Requests\Organizations\ListOrganizationsRequest;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère les organisations demandeuses du bus communal (règles : OrganizationService).
 * Les agents et admins voient toutes les organisations, avec recherche, filtres et tri ;
 * les autres utilisateurs ne voient que celles auxquelles ils sont rattachés.
 * Liste et détail renvoient le quota de l'année, le nombre de membres et de réservations à venir.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizationService
    ) {}

    public function index(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        return OrganizationResource::collection(
            $this->organizationService->list($request->user(), $request->validated())->withQueryString()
        );
    }

    /**
     * GET /organizations/types : les types d'organisation, leur libellé et leurs règles (config/reservations.php),
     * pour annoncer l'effet d'un changement de type sans recopier la configuration dans le front.
     */
    public function types(): JsonResponse
    {
        Gate::authorize('viewAny', Organization::class);

        return response()->json([
            'data' => array_map(fn (OrganizationType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'rules' => $type->rules(),
            ], OrganizationType::cases()),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->organizationService->create($request->validated(), $request->user());

        return (new OrganizationResource($this->withCounts($organization)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource($this->withCounts($organization));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        $organization = $this->organizationService->update($organization, $request->validated(), $request->user());

        return new OrganizationResource($this->withCounts($organization));
    }

    /**
     * POST /organizations/{id}/ban : `reason` (obligatoire), `cancel_upcoming` (annuler aussi ses réservations à venir).
     * `meta.cancelled_reservation_ids`, `meta.skipped` (réservations qui n'ont pas pu être annulées, avec la raison).
     */
    public function ban(BanOrganizationRequest $request, Organization $organization): JsonResponse
    {
        // La policy BanOrganizationRequest vérifie déjà la permission organizations.ban (admin uniquement).
        $result = $this->organizationService->ban(
            $organization,
            $request->validated('reason'),
            $request->user(),
            (bool) $request->validated('cancel_upcoming', false)
        );

        return (new OrganizationResource($this->withCounts($result['organization'])))
            ->additional(['meta' => [
                'cancelled_reservation_ids' => $result['cancelled'],
                'skipped' => $result['skipped'],
            ]])
            ->response();
    }

    public function unban(Request $request, Organization $organization): OrganizationResource
    {
        // Réutilise la permission 'ban' pour le débannissement (même niveau d'accès requis).
        Gate::authorize('ban', $organization);

        return new OrganizationResource($this->withCounts($this->organizationService->unban($organization, $request->user())));
    }

    /** Recharge l'organisation avec son lieu et les comptes du détail (membres, réservations à venir, quota) */
    private function withCounts(Organization $organization): Organization
    {
        return $this->organizationService
            ->withListCounts(Organization::query()->with('location'))
            ->findOrFail($organization->getKey());
    }
}
