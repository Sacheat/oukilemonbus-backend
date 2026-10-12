<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Tutoriels de première connexion de la SPA : retient ceux que l'utilisateur courant a vus.
 * Toujours scopé sur l'utilisateur courant, comme les préférences de notification.
 */
class TourController extends Controller
{
    /** Marque le tutoriel comme vu ; garde la première date si c'est déjà le cas. */
    public function markSeen(Request $request, string $tour): JsonResponse
    {
        $this->validateTour($tour);

        $user = $request->user();
        $seen = $user->tours_seen ?? [];
        $seen[$tour] ??= now()->toISOString();
        $user->forceFill(['tours_seen' => $seen])->save();

        return $this->respond($seen);
    }

    /** Oublie le tutoriel (« Revoir le tutoriel ») : il sera proposé à la prochaine visite de l'espace. */
    public function reset(Request $request, string $tour): JsonResponse
    {
        $this->validateTour($tour);

        $user = $request->user();
        $seen = $user->tours_seen ?? [];
        unset($seen[$tour]);
        $user->forceFill(['tours_seen' => $seen === [] ? null : $seen])->save();

        return $this->respond($seen);
    }

    private function validateTour(string $tour): void
    {
        Validator::make(
            ['tour' => $tour],
            ['tour' => ['required', Rule::in(User::TOURS)]],
            ['tour.in' => 'Ce tutoriel n’existe pas.'],
        )->validate();
    }

    private function respond(array $seen): JsonResponse
    {
        return response()->json(['data' => ['tours_seen' => (object) $seen]]);
    }
}
