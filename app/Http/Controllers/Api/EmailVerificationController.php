<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Vérifie l'email à partir des paramètres du lien signé envoyé par notification.
     * Appelée par la SPA (le lien de l'email pointe vers le frontend, pas vers l'API).
     * N'exige pas d'authentification — la signature (relative) garantit la sécurité.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new AuthorizationException('Lien de vérification invalide.');
        }

        if (! $request->hasValidSignature(false)) {
            throw new AuthorizationException('Lien de vérification expiré ou invalide.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['message' => 'Adresse email vérifiée avec succès.']);
    }

    /**
     * Renvoie l'email de vérification à l'utilisateur authentifié.
     */
    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Adresse email déjà vérifiée.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Email de vérification envoyé.']);
    }
}