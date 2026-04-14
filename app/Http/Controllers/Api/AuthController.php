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

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

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
            $request->hitRateLimiter();

            throw $exception;
        }

        $request->clearRateLimiter();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new UserResource($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
