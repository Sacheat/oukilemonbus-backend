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
use Illuminate\Validation\Rule;

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
     * `role` : comptes qui ont ce rôle global. `active` : 1/true (actifs) ou 0/false (désactivés).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        // La règle `boolean` refuse les chaînes « true »/« false » d'une query string.
        if (is_string($active = $request->query('active')) && in_array(strtolower($active), ['true', 'false'], true)) {
            $request->merge(['active' => strtolower($active) === 'true' ? '1' : '0']);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(User::GLOBAL_ROLES)],
            'active' => ['nullable', 'boolean'],
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
                ->when($validated['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
                ->when(isset($validated['active']), fn (Builder $query) => $query->where('active', (bool) $validated['active']))
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

        return new UserResource($user->load(['roles', 'organizations', 'driver']));
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

    /**
     * Envoie le lien de « Mot de passe oublié » à l'email du compte, pour aider une personne sans lui
     * transmettre de mot de passe. Le jeton n'apparaît jamais dans la réponse.
     */
    public function sendPasswordResetLink(User $user): JsonResponse
    {
        Gate::authorize('sendPasswordReset', $user);

        $this->userService->sendPasswordResetLink($user);

        return response()->json([
            'message' => "Un lien de réinitialisation a été envoyé à {$user->email}.",
        ], 202);
    }

    public function deactivate(User $user): JsonResponse
    {
        Gate::authorize('deactivate', $user);

        $this->userService->deactivate($user);

        return response()->json(null, 204);
    }
}
