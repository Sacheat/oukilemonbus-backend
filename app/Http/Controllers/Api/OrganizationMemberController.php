<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRequest;
use App\Http\Resources\OrganizationMemberResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Membres d'une organisation.
 * Lecture : membres de l'organisation et internes. Gestion (type, retrait) : responsables
 * de l'organisation et internes. Quitter / organisation par défaut : le membre lui-même.
 */
class OrganizationMemberController extends Controller
{
    public function __construct(
        private readonly OrganizationMembershipService $membershipService
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        return OrganizationMemberResource::collection($this->membershipService->members($organization));
    }

    public function update(UpdateOrganizationMemberRequest $request, Organization $organization, User $user): OrganizationMemberResource
    {
        return new OrganizationMemberResource(
            $this->membershipService->updateMember($organization, $user, $request->validated('relationship_type'), $request->user())
        );
    }

    public function destroy(Request $request, Organization $organization, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);

        $this->membershipService->removeMember($organization, $user, $request->user());

        return response()->json(null, 204);
    }

    public function leave(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('leave', $organization);

        $this->membershipService->leave($organization, $request->user());

        return response()->json(null, 204);
    }

    public function makeDefault(Request $request, Organization $organization): UserResource
    {
        Gate::authorize('leave', $organization);

        return new UserResource(
            $this->membershipService->setDefault($organization, $request->user())->load('roles')
        );
    }
}
