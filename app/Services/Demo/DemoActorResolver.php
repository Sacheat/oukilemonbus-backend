<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Résout l'acteur courant de la session démo.
 *
 * `current()` lit l'ID utilisateur depuis la session (clé 'demo_user_id').
 * Si l'utilisateur n'existe plus ou est inactif, bascule sur le premier admin/agent actif
 * trouvé en base, puis sur n'importe quel utilisateur actif (ordre de priorité décroissant).
 * Retourne 503 si aucun utilisateur actif n'est disponible.
 *
 * `set()` change l'acteur en session et retourne le nouvel utilisateur.
 * `availableActors()` liste tous les utilisateurs actifs pour le sélecteur d'acteur de la démo.
 */
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
