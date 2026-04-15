<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizationService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::query()
                ->with('location')
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->organizationService->create($request->validated());

        return (new OrganizationResource($organization->load('location')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization->load(['location', 'users']));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        $organization = $this->organizationService->update($organization, $request->validated());

        return new OrganizationResource($organization->load('location'));
    }
}
