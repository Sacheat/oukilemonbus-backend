<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Http\Resources\UserNotificationPreferenceResource;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService
    ) {}

    public function show(Request $request): UserNotificationPreferenceResource
    {
        return new UserNotificationPreferenceResource(
            $this->preferenceService->forUser($request->user())
        );
    }

    public function update(UpdateNotificationPreferencesRequest $request): UserNotificationPreferenceResource
    {
        return new UserNotificationPreferenceResource(
            $this->preferenceService->update($request->user(), $request->validated())
        );
    }
}

