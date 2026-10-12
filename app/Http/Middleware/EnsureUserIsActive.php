<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            return response()->json(['message' => 'Ce compte a été désactivé.'], Response::HTTP_FORBIDDEN);
        }

        if ($user instanceof User) {
            $this->recordActivity($user);
        }

        return $next($request);
    }

    /**
     * RGPD, comptes inactifs : dernière activité, au plus une écriture par jour. Une session « se souvenir de moi »
     * ne repasse pas par la connexion : c'est l'activité, pas la connexion, qui compte. Revenir annule l'avertissement.
     * Écriture directe : updated_at (modification du profil) ne bouge pas.
     */
    private function recordActivity(User $user): void
    {
        if ($user->last_seen_at !== null && $user->last_seen_at->isToday()) {
            return;
        }

        $now = now();
        DB::table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update(['last_seen_at' => $now, 'inactivity_warned_at' => null]);

        $user->forceFill(['last_seen_at' => $now, 'inactivity_warned_at' => null])
            ->syncOriginalAttributes(['last_seen_at', 'inactivity_warned_at']);
    }
}
