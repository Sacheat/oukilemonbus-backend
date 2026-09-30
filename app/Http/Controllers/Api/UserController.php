<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdatePasswordRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserRolesRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD utilisateurs.
 * `updateStatus` active ou désactive un compte sans le supprimer.
 * `deactivate` est une action sémantiquement distincte, contrôlée par une Policy dédiée.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    /**
     * `search` : chaque mot doit apparaître dans le prénom, le nom ou l'email (ex. « paul sec »).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $patterns = LikeSearch::patterns($validated['search'] ?? null);

        return UserResource::collection(
            User::query()
                ->with('roles')
                ->when($patterns !== [], function (Builder $query) use ($patterns): void {
                    foreach ($patterns as $like) {
                        $query->where(fn (Builder $q) => $q
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like));
                    }
                })
                ->latest()
                ->paginate()
                ->withQueryString()
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user = $this->userService->update($user, $request->validated());

        return new UserResource($user);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $user = $this->userService->updateStatus(
            $user,
            $request->boolean('active')
        );

        return new UserResource($user);
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        return new UserResource(
            $this->userService->updateRoles($user, $request->validated('roles'))
        );
    }

    /**
     * Change le mot de passe sans fermer la session qui fait le changement : l'utilisateur de la garde
     * reçoit le nouveau hash, que le middleware AuthenticateSession (Sanctum) enregistre en session
     * en fin de requête. Les autres sessions et les tokens API de l'utilisateur sont fermés.
     */
    public function updatePassword(UpdatePasswordRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->updatePassword($user, $request->validated('password'));

        $currentSessionId = null;
        if ($request->user()?->is($user)) {
            Auth::guard('web')->setUser($user);

            if ($request->hasSession()) {
                $request->session()->regenerate();
                $currentSessionId = $request->session()->getId();
            }
        }

        $this->userService->closeOtherSessions($user, $currentSessionId);

        return response()->json(null, 204);
    }

    public function deactivate(User $user): JsonResponse
    {
        Gate::authorize('deactivate', $user);

        $this->userService->deactivate($user);

        return response()->json(null, 204);
    }
}
