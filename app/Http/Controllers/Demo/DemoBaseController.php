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

    protected function runDemoAction(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();

            return back()->with('status', $successMessage);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    protected function authorizeFor(User $actor, string $ability, mixed $arguments = []): void
    {
        Gate::forUser($actor)->authorize($ability, $arguments);
    }
}
