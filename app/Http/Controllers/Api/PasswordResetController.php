<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Mot de passe oublié. Le lien de l'email pointe vers la SPA
 * (FRONTEND_URL + frontend_password_reset_path?token=&email=), qui appelle ensuite reset().
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->sendResetLink($request->validated('email'));

        // Réponse identique que le compte existe ou non : ne révèle pas les adresses inscrites.
        return response()->json([
            'message' => 'Si un compte correspond à cette adresse, un email de réinitialisation vient d’être envoyé.',
        ]);
    }

    /**
     * Accessible avec ou sans session. Les sessions du compte réinitialisé sont fermées par le service ; si la
     * session courante est la sienne, elle est fermée aussi (sinon le middleware de session la réécrirait en fin
     * de requête). La session d'un autre compte reste ouverte.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->passwordResetService->reset($request->validated());

        if (Auth::guard('web')->id() === $user->getKey()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.',
        ]);
    }
}
