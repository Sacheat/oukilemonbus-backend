<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouncilDecisions\StoreCouncilDecisionRequest;
use App\Http\Requests\CouncilDecisions\UpdateCouncilDecisionRequest;
use App\Http\Resources\CouncilDecisionResource;
use App\Models\CouncilDecision;
use App\Services\CouncilDecisions\CouncilDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DeliberationDocumentService;
use InvalidArgumentException;

class CouncilDecisionController extends Controller
{
    public function __construct(
        private readonly CouncilDecisionService $councilDecisionService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CouncilDecision::class);

        $user = request()->user();

        return CouncilDecisionResource::collection(
            CouncilDecision::query()
                ->with(['reservation.organization', 'recordedByUser'])
                ->when(! $user->hasAnyRole(['admin', 'agent_communal']), function ($query) use ($user): void {
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
                ->latest('decision_date')
                ->latest()
                ->paginate()
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
    ): DocumentResource {
        Gate::authorize('generate', Document::class);

        try {
            $document = $deliberationDocumentService->generateFor($councilDecision);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return new DocumentResource($document);
    }
}
