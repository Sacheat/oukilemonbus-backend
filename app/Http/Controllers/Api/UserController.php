<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdatePasswordRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()
                ->latest()
                ->paginate()
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

    public function updatePassword(UpdatePasswordRequest $request, User $user): JsonResponse
    {
        $this->userService->updatePassword($user, $request->validated('password'));

        return response()->json(null, 204);
    }

    public function deactivate(User $user): JsonResponse
    {
        $this->userService->deactivate($user);

        return response()->json(null, 204);
    }
}
