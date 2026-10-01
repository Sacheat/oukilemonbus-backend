<?php

namespace App\Http\Controllers\Api;

use App\Enums\CouncilDecisionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CouncilDecisions\StoreCouncilDecisionRequest;
use App\Http\Requests\CouncilDecisions\UpdateCouncilDecisionRequest;
use App\Http\Resources\CouncilDecisionResource;
use App\Models\CouncilDecision;
use App\Services\CouncilDecisions\CouncilDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DeliberationDocumentService;
use InvalidArgumentException;

/**
 * Gère les décisions de conseil municipal liées aux réservations en conflit.
 * Les admins et agents voient toutes les décisions ; les autres sont filtrés :
 * réservation faite par eux, organisation dont ils sont membres, ou chauffeur assigné.
 * `generateDocument` produit le PDF de délibération via DeliberationDocumentService.
 */
class CouncilDecisionController extends Controller
{
    public function __construct(
        private readonly CouncilDecisionService $councilDecisionService
    ) {}

    /**
     * Filtres : `reservation_id`, `decision_status` (approuve, refuse, en_attente ; 422 sinon).
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CouncilDecision::class);

        $validated = request()->validate(
            ['decision_status' => ['sometimes', Rule::enum(CouncilDecisionStatus::class)]],
            ['decision_status.enum' => 'Le statut de décision doit valoir approuve, refuse ou en_attente.'],
        );

        $user = request()->user();

        return CouncilDecisionResource::collection(
            CouncilDecision::query()
                ->with(['reservation.organization', 'recordedByUser'])
                ->when(! $user->hasAnyRole(['admin', 'agent_communal']), function ($query) use ($user): void {
                    // Filtre multi-critères : demandeur OU organisation OU chauffeur assigné.
                    $query->whereHas('reservation', function ($query) use ($user): void {
                        $query->where('requested_by_user_id', $user->getKey())
                            ->orWhereHas('organization.users', fn ($query) => $query->whereKey($user->getKey()))
                            ->when(
                                $user->hasRole('chauffeur') && $user->driver !== null,
                                fn ($query) => $query->orWhere('driver_id', $user->driver->getKey())
                            );
                    });
                })
                ->when(request('reservation_id'), fn ($query, $reservationId) => $query->where('reservation_id', $reservationId))
                ->when($validated['decision_status'] ?? null, fn ($query, $status) => $query->where('decision_status', $status))
                ->latest('decision_date')
                ->latest()
                ->paginate()
                ->withQueryString()
        );
    }

    public function store(StoreCouncilDecisionRequest $request): JsonResponse
    {
        $councilDecision = $this->councilDecisionService->create($request->validated(), $request->user());

        return (new CouncilDecisionResource($councilDecision))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CouncilDecision $councilDecision): CouncilDecisionResource
    {
        Gate::authorize('view', $councilDecision);

        return new CouncilDecisionResource(
            $councilDecision->load(['reservation.organization', 'recordedByUser'])
        );
    }

    public function update(UpdateCouncilDecisionRequest $request, CouncilDecision $councilDecision): CouncilDecisionResource
    {
        $councilDecision = $this->councilDecisionService->update($councilDecision, $request->validated(), $request->user());

        return new CouncilDecisionResource($councilDecision);
    }

    public function generateDocument(
        CouncilDecision $councilDecision,
        DeliberationDocumentService $deliberationDocumentService
    ): JsonResponse {
        Gate::authorize('generate', Document::class);

        try {
            $document = $deliberationDocumentService->generateFor($councilDecision);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }
}
