<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

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

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->reset($request->validated());

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.',
        ]);
    }
}
