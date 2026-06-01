<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\Demo\DemoActorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

abstract class DemoBaseController extends Controller
{
    public function __construct(
        protected readonly DemoActorResolver $actorResolver,
    ) {
    }

    protected function actor(Request $request): User
    {
        return $this->actorResolver->current($request);
    }

    protected function shared(Request $request): array
    {
        $actor = $this->actor($request);

        return [
            'demoUsers' => $this->actorResolver->availableActors(),
            'actor' => $actor,
            'actorRoles' => $actor->getRoleNames(),
            'pendingOrgRequests' => OrgRegistrationRequest::query()->where('status', 'en_attente')->count(),
            'pendingUserOrgRequests' => UserOrgRequest::query()->where('status', 'en_attente')->count(),
        ];
    }

    protected function runDemoAction(callable $action, string $successMessage, ?Request $request = null, ?string $inspectorAction = null, array $inspectorContext = []): RedirectResponse {
        try {
            $action();

            if ($request !== null && $inspectorAction !== null) {
                $this->recordInspectorAction($request, $inspectorAction, array_merge($inspectorContext, [
                    'status' => 'success',
                    'message' => $successMessage,
                ]));

                $request->session()->put('backend_inspector.skip_next_view_recording', true);
            }

            return back()->with('status', $successMessage);
        } catch (Throwable $exception) {
            report($exception);

            if ($request !== null && $inspectorAction !== null) {
                $this->recordInspectorAction($request, $inspectorAction, array_merge($inspectorContext, [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]));

                $request->session()->put('backend_inspector.skip_next_view_recording', true);
            }

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    protected function authorizeFor(User $actor, string $ability, mixed $arguments = []): void
    {
        Gate::forUser($actor)->authorize($ability, $arguments);
    }

    protected function recordInspectorView(Request $request, string $actionKey, array $context = []): void
    {
        if ($request->session()->pull('backend_inspector.skip_next_view_recording', false)) {
            return;
        }

        $this->recordInspectorAction($request, $actionKey, $context);
    }

    protected function recordInspectorAction(Request $request, ?string $actionKey, array $context = []): void
    {
        if ($actionKey === null) {
            return;
        }

        if (! config('backend_inspector.enabled', false)) {
            return;
        }

        $sessionKeys = config('backend_inspector.session_keys', []);

        $request->session()->put([
            $sessionKeys['last_action'] ?? 'backend_inspector.last_action' => $actionKey,
            $sessionKeys['last_context'] ?? 'backend_inspector.last_context' => $context,
            $sessionKeys['last_recorded_at'] ?? 'backend_inspector.last_recorded_at' => now()->toDateTimeString(),
        ]);
    }

}
