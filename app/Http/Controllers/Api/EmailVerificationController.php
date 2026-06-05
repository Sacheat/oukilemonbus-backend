<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Vérifie l'email via le lien signé envoyé par notification.
     * N'exige pas d'authentification — la signature garantit la sécurité.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new AuthorizationException('Lien de vérification invalide.');
        }

        if (! $request->hasValidSignature()) {
            throw new AuthorizationException('Lien de vérification expiré ou invalide.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Requête navigateur → redirection vers la démo avec indicateur de succès
        if (! $request->expectsJson()) {
            return redirect(url('/demo/v2') . '#/login?verified=1');
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