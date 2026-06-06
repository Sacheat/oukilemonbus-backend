<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\BackendInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BackendInspectorController extends Controller
{
    public function __construct(
        private readonly BackendInspectorService $backendInspectorService,
    ) {
    }

    public function live(): View
    {
        abort_unless(config('backend_inspector.enabled', false), 404);

        return view('demo.inspector.live');
    }

    public function state(Request $request): JsonResponse
    {
        abort_unless(config('backend_inspector.enabled', false), 404);

        $actor = $this->currentDemoActor($request);

        return response()->json(
            $this->backendInspectorService->state($request, $actor)
        );
    }

    private function currentDemoActor(Request $request): ?User
    {
        // v2 demo: actor ID stored by BackendInspectorMiddleware after each API action
        $actorId = $request->session()->get(
            config('backend_inspector.session_keys.current_actor_id', 'backend_inspector.current_actor_id')
        );

        if ($actorId !== null) {
            return User::query()->find($actorId);
        }

        // Old demo fallback: session-based selected actor
        $userId = $request->session()->get('demo_user_id');

        if ($userId !== null) {
            return User::query()->find($userId);
        }

        return null;
    }
}
