<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Organizations\OrganizationService;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gère l'authentification SPA via Sanctum (cookies de session).
 * Le CSRF cookie doit être initialisé par le frontend avant tout appel (GET /sanctum/csrf-cookie).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        // Régénère l'ID de session pour prévenir la fixation de session après création de compte.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        $request->ensureIsNotRateLimited();

        try {
            $user = $this->authService->login(
                $request->safe()->only(['email', 'password']),
                $request->boolean('remember')
            );
        } catch (ValidationException $exception) {
            // Incrémente le compteur de tentatives échouées avant de relancer l'exception.
            $request->hitRateLimiter();

            throw $exception;
        }

        $request->clearRateLimiter();

        // Régénère l'ID de session pour prévenir la fixation de session après authentification.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Rôles et organisations chargés ici pour éviter un aller-retour supplémentaire depuis le frontend.
        return (new UserResource($this->withOrganizations($user)))->withEffectivePermissions();
    }

    public function me(Request $request): UserResource
    {
        return (new UserResource($this->withOrganizations($request->user())))->withEffectivePermissions();
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        if ($request->hasSession()) {
            // Invalide la session et régénère le token CSRF pour nettoyer complètement la session active.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    /**
     * Rôles et organisations de l'utilisateur, avec le quota de l'année, les membres et les réservations à venir de
     * chaque organisation (une requête, sous-requêtes de comptage) : la jauge du quota de l'espace usager est juste.
     */
    private function withOrganizations(User $user): User
    {
        return $user->load([
            'roles',
            'organizations' => fn ($organizations) => app(OrganizationService::class)->withListCounts($organizations),
        ]);
    }
}
