<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrgRegistrationRequests\ApproveOrgRegistrationRequest;
use App\Http\Requests\OrgRegistrationRequests\ListOrgRegistrationRequestsRequest;
use App\Http\Requests\OrgRegistrationRequests\RejectOrgRegistrationRequest;
use App\Http\Requests\Documents\StoreProofDocumentRequest;
use App\Http\Requests\OrgRegistrationRequests\StoreOrgRegistrationRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\OrgRegistrationRequestResource;
use App\Models\OrgRegistrationRequest;
use App\Services\Documents\ProofDocumentService;
use App\Services\OrgRegistrationRequests\OrgRegistrationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère les demandes d'inscription d'organisation.
 * L'approbation crée l'organisation et retourne un 201 avec OrganizationResource.
 * Le justificatif (proof_document) remplace l'ancien fichier si un upload précédent existe déjà.
 */
class OrgRegistrationRequestController extends Controller
{
    public function __construct(
        private readonly OrgRegistrationRequestService $orgRegistrationRequestService,
        private readonly ProofDocumentService $proofDocumentService,
    ) {}

    /**
     * Filtres (ListOrgRegistrationRequestsRequest) : statuses[], q, organization_type, sort (confiance par défaut :
     * les demandes les plus fiables remontent), per_page. Ils s'ajoutent au périmètre visible, qui ne change pas.
     */
    public function index(ListOrgRegistrationRequestsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = OrgRegistrationRequest::query()
            ->with(['requestedByUser', 'reviewedByUser', 'location'])
            ->when(
                // Les non-internes ne voient que leurs propres demandes.
                ! $user->hasAnyRole(['admin', 'agent_communal']),
                fn ($query) => $query->where('request_by_user_id', $user->getKey())
            )
            ->when($request->validated('organization_type'), fn ($query, $type) => $query->where('organization_type', $type));

        $request->applySearch($query, ['organization_name', 'official_email', 'enterprise_number'], 'requestedByUser');
        $request->applyStatusAndSort($query, 'id');

        return OrgRegistrationRequestResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreOrgRegistrationRequest $request): JsonResponse
    {
        $registrationRequest = $this->orgRegistrationRequestService->create(
            $request->user(),
            $request->validated()
        );

        return (new OrgRegistrationRequestResource($registrationRequest->load(['requestedByUser', 'location'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(OrgRegistrationRequest $orgRegistrationRequest): OrgRegistrationRequestResource
    {
        Gate::authorize('view', $orgRegistrationRequest);

        return new OrgRegistrationRequestResource(
            $orgRegistrationRequest->load(['requestedByUser', 'reviewedByUser', 'location'])
        );
    }

    public function approve(
        ApproveOrgRegistrationRequest $request,
        OrgRegistrationRequest $orgRegistrationRequest
    ): JsonResponse {
        $organization = $this->orgRegistrationRequestService->approve(
            $orgRegistrationRequest,
            $request->user()
        );

        return (new OrganizationResource($organization->load('location')))
            ->response()
            ->setStatusCode(201);
    }

    public function reject(
        RejectOrgRegistrationRequest $request,
        OrgRegistrationRequest $orgRegistrationRequest
    ): OrgRegistrationRequestResource {
        $registrationRequest = $this->orgRegistrationRequestService->reject(
            $orgRegistrationRequest,
            $request->user(),
            $request->validated('rejection_reason')
        );

        return new OrgRegistrationRequestResource(
            $registrationRequest->load(['requestedByUser', 'reviewedByUser', 'location'])
        );
    }

    public function uploadProof(StoreProofDocumentRequest $request, OrgRegistrationRequest $orgRegistrationRequest): JsonResponse
    {
        Gate::authorize('update', $orgRegistrationRequest);

        if ($orgRegistrationRequest->proofDocument) {
            $this->proofDocumentService->delete($orgRegistrationRequest->proofDocument);
        }

        $document = $this->proofDocumentService->store(
            $request->file('proof_document'),
            'org-registration-requests',
            $orgRegistrationRequest->id
        );

        $orgRegistrationRequest->update(['proof_document_id' => $document->id]);
        $this->orgRegistrationRequestService->refreshConfidenceNote($orgRegistrationRequest);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function deleteProof(OrgRegistrationRequest $orgRegistrationRequest): JsonResponse
    {
        Gate::authorize('update', $orgRegistrationRequest);

        if ($orgRegistrationRequest->proofDocument) {
            $this->proofDocumentService->delete($orgRegistrationRequest->proofDocument);
            $orgRegistrationRequest->update(['proof_document_id' => null]);
            $this->orgRegistrationRequestService->refreshConfidenceNote($orgRegistrationRequest);
        }

        return response()->json(null, 204);
    }
}
