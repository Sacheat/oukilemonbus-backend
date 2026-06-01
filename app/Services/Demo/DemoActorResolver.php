<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

final class DemoActorResolver
{
    public const SESSION_KEY = 'demo_user_id';

    public function current(Request $request): User
    {
        $sessionUserId = $request->session()->get(self::SESSION_KEY);
        $sessionUser = $sessionUserId ? User::query()->whereKey($sessionUserId)->first() : null;

        if ($sessionUser instanceof User && $sessionUser->active) {
            return $sessionUser->loadMissing(['roles', 'organizations', 'driver']);
        }

        $defaultUser = User::query()
            ->where('active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'agent_communal']))
            ->first()
            ?? User::query()->where('active', true)->first();

        abort_if($defaultUser === null, 503, 'Aucun utilisateur actif disponible pour la demo.');

        $request->session()->put(self::SESSION_KEY, $defaultUser->id);

        return $defaultUser->loadMissing(['roles', 'organizations', 'driver']);
    }

    public function set(Request $request, int $userId): User
    {
        $user = User::query()
            ->where('active', true)
            ->whereKey($userId)
            ->firstOrFail();

        $request->session()->put(self::SESSION_KEY, $user->id);

        return $user->loadMissing(['roles', 'organizations', 'driver']);
    }

    /**
     * @return Collection<int, User>
     */
    public function availableActors(): Collection
    {
        return User::query()
            ->with(['roles', 'organizations', 'driver'])
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }
}
