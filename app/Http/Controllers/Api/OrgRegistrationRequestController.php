<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrgRegistrationRequests\ApproveOrgRegistrationRequest;
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

class OrgRegistrationRequestController extends Controller
{
    public function __construct(
        private readonly OrgRegistrationRequestService $orgRegistrationRequestService,
        private readonly ProofDocumentService $proofDocumentService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OrgRegistrationRequest::class);

        $user = request()->user();

        return OrgRegistrationRequestResource::collection(
            OrgRegistrationRequest::query()
                ->with(['requestedByUser', 'reviewedByUser', 'location'])
                ->when(
                    ! $user->hasAnyRole(['admin', 'agent_communal']),
                    fn ($query) => $query->where('request_by_user_id', $user->getKey())
                )
                ->orderByDesc('confidence_note')
                ->latest()
                ->paginate()
        );
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

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function deleteProof(OrgRegistrationRequest $orgRegistrationRequest): JsonResponse
    {
        Gate::authorize('update', $orgRegistrationRequest);

        if ($orgRegistrationRequest->proofDocument) {
            $this->proofDocumentService->delete($orgRegistrationRequest->proofDocument);
            $orgRegistrationRequest->update(['proof_document_id' => null]);
        }

        return response()->json(null, 204);
    }
}
