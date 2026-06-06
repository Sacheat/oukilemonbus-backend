<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\BackendInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BackendInspectorController extends Controller
{
    public function __construct(
        private readonly BackendInspectorService $backendInspectorService,
    ) {
    }

    public function state(Request $request): JsonResponse
    {
        abort_unless(config('backend_inspector.enabled', false), 404);

        $actor = $this->currentDemoActor($request);

        return response()->json(
            $this->backendInspectorService->state($request, $actor)
        );
    }

    public function reset(Request $request): JsonResponse
    {
        abort_unless(config('backend_inspector.enabled', false), 404);

        $keys = config('backend_inspector.session_keys', []);

        $request->session()->forget([
            $keys['last_action']      ?? 'backend_inspector.last_action',
            $keys['last_context']     ?? 'backend_inspector.last_context',
            $keys['last_recorded_at'] ?? 'backend_inspector.last_recorded_at',
            $keys['history']          ?? 'backend_inspector.history',
            $keys['current_scenario'] ?? 'backend_inspector.current_scenario',
            $keys['current_actor_id'] ?? 'backend_inspector.current_actor_id',
        ]);

        return response()->json(['reset' => true]);
    }

    private function currentDemoActor(Request $request): ?User
    {
        $actorId = $request->session()->get(
            config('backend_inspector.session_keys.current_actor_id', 'backend_inspector.current_actor_id')
        );

        return $actorId !== null ? User::query()->find($actorId) : null;
    }
}
