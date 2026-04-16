<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserOrgRequests\ApproveUserOrgRequest;
use App\Http\Requests\UserOrgRequests\RejectUserOrgRequest;
use App\Http\Requests\UserOrgRequests\StoreUserOrgRequest;
use App\Http\Resources\UserOrgRequestResource;
use App\Models\UserOrgRequest;
use App\Services\UserOrgRequests\UserOrgRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserOrgRequestController extends Controller
{
    public function __construct(
        private readonly UserOrgRequestService $userOrgRequestService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return UserOrgRequestResource::collection(
            UserOrgRequest::query()
                ->with(['organization', 'requestedByUser', 'reviewedByUser'])
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
}
