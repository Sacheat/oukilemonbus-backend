<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Http\Resources\UserNotificationPreferenceResource;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gère les préférences de notification de l'utilisateur authentifié.
 * Toujours scopé sur l'utilisateur courant — pas de route paramétrée par ID.
 * Crée automatiquement les préférences par défaut si elles n'existent pas encore.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return (new UserNotificationPreferenceResource(
            $this->preferenceService->forUser($request->user())
        ))->response()->setStatusCode(200);
    }

    public function update(UpdateNotificationPreferencesRequest $request): UserNotificationPreferenceResource
    {
        return new UserNotificationPreferenceResource(
            $this->preferenceService->update($request->user(), $request->validated())
        );
    }
}
