<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
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
        return (new UserResource($user->load('roles', 'organizations')))->withEffectivePermissions();
    }

    public function me(Request $request): UserResource
    {
        return (new UserResource($request->user()->load('roles', 'organizations')))->withEffectivePermissions();
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
}
