<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserOrgRequests\ApproveUserOrgRequest;
use App\Http\Requests\UserOrgRequests\RejectUserOrgRequest;
use App\Http\Requests\Documents\StoreProofDocumentRequest;
use App\Http\Requests\UserOrgRequests\StoreUserOrgRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserOrgRequestResource;
use App\Models\Organization;
use App\Models\UserOrgRequest;
use App\Services\Documents\ProofDocumentService;
use App\Services\UserOrgRequests\UserOrgRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère les demandes de rattachement d'un utilisateur à une organisation.
 * availableOrganizations() exclut les organisations dont l'utilisateur est déjà membre
 * ou pour lesquelles il a déjà une demande en attente, évitant les doublons.
 */
class UserOrgRequestController extends Controller
{
    public function __construct(
        private readonly UserOrgRequestService $userOrgRequestService,
        private readonly ProofDocumentService $proofDocumentService,
    ) {}

    public function availableOrganizations(): AnonymousResourceCollection
    {
        $user = request()->user();

        abort_unless($user->can('user-org-requests.create'), 403);

        // Organisations dont l'utilisateur est déjà membre (pivot user_organization).
        $memberOrgIds = $user->organizations()->pluck('organizations.id');

        // Organisations avec une demande EN_ATTENTE existante pour cet utilisateur.
        $pendingOrgIds = UserOrgRequest::query()
            ->where('requested_by_user_id', $user->getKey())
            ->where('status', RequestStatus::EN_ATTENTE)
            ->pluck('organization_id');

        $excludedIds = $memberOrgIds->merge($pendingOrgIds)->unique();

        return OrganizationResource::collection(
            Organization::query()
                ->whereNotIn('id', $excludedIds)
                ->where('is_banned', false)
                ->orderBy('name')
                ->get()
        );
    }

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', UserOrgRequest::class);

        $user = request()->user();

        return UserOrgRequestResource::collection(
            UserOrgRequest::query()
                ->with(['organization', 'requestedByUser', 'reviewedByUser'])
                ->when(
                    ! $user->hasAnyRole(['admin', 'agent_communal']),
                    fn ($query) => $query->where(function ($query) use ($user): void {
                        // Ses propres demandes + celles adressées aux organisations qu'il gère.
                        $query->where('requested_by_user_id', $user->getKey())
                            ->orWhereIn('organization_id', $user->managedOrganizationIds());
                    })
                )
                ->orderByDesc('confidence_note')
                ->latest()
                ->paginate()
        );
    }

    public function store(StoreUserOrgRequest $request): JsonResponse
    {
        $userOrgRequest = $this->userOrgRequestService->create(
            $request->user(),
            $request->validated()
        );

        return (new UserOrgRequestResource($userOrgRequest->load(['organization', 'requestedByUser'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(UserOrgRequest $userOrgRequest): UserOrgRequestResource
    {
        Gate::authorize('view', $userOrgRequest);

        return new UserOrgRequestResource(
            $userOrgRequest->load(['organization', 'requestedByUser', 'reviewedByUser'])
        );
    }

    public function approve(ApproveUserOrgRequest $request, UserOrgRequest $userOrgRequest): UserOrgRequestResource
    {
        $userOrgRequest = $this->userOrgRequestService->approve(
            $userOrgRequest,
            $request->user(),
            $request->validated('confidence_note')
        );

        return new UserOrgRequestResource(
            $userOrgRequest->load(['organization', 'requestedByUser', 'reviewedByUser'])
        );
    }

    public function reject(RejectUserOrgRequest $request, UserOrgRequest $userOrgRequest): UserOrgRequestResource
    {
        $userOrgRequest = $this->userOrgRequestService->reject(
            $userOrgRequest,
            $request->user(),
            $request->validated('rejection_reason'),
            $request->validated('confidence_note')
        );

        return new UserOrgRequestResource(
            $userOrgRequest->load(['organization', 'requestedByUser', 'reviewedByUser'])
        );
    }

    public function uploadProof(StoreProofDocumentRequest $request, UserOrgRequest $userOrgRequest): JsonResponse
    {
        Gate::authorize('update', $userOrgRequest);

        if ($userOrgRequest->proofDocument) {
            $this->proofDocumentService->delete($userOrgRequest->proofDocument);
        }

        $document = $this->proofDocumentService->store(
            $request->file('proof_document'),
            'user-org-requests',
            $userOrgRequest->request_id
        );

        $userOrgRequest->update(['proof_document_id' => $document->id]);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function deleteProof(UserOrgRequest $userOrgRequest): JsonResponse
    {
        Gate::authorize('update', $userOrgRequest);

        if ($userOrgRequest->proofDocument) {
            $this->proofDocumentService->delete($userOrgRequest->proofDocument);
            $userOrgRequest->update(['proof_document_id' => null]);
        }

        return response()->json(null, 204);
    }
}
