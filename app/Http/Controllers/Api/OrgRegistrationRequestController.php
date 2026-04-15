<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrgRegistrationRequests\ApproveOrgRegistrationRequest;
use App\Http\Requests\OrgRegistrationRequests\RejectOrgRegistrationRequest;
use App\Http\Requests\OrgRegistrationRequests\StoreOrgRegistrationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\OrgRegistrationRequestResource;
use App\Models\OrgRegistrationRequest;
use App\Services\OrgRegistrationRequests\OrgRegistrationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrgRegistrationRequestController extends Controller
{
    public function __construct(
        private readonly OrgRegistrationRequestService $orgRegistrationRequestService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return OrgRegistrationRequestResource::collection(
            OrgRegistrationRequest::query()
                ->with(['requestedByUser', 'reviewedByUser', 'location'])
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
}
