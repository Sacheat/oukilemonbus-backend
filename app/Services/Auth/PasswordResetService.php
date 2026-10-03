<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réinitialisation de mot de passe via le broker Laravel (table password_reset_tokens, jeton valable 60 min).
 *
 * reset() invalide aussi toutes les sessions et tokens API de l'utilisateur :
 * un mot de passe compromis ne doit laisser aucun accès ouvert.
 */
class PasswordResetService
{
    public function sendResetLink(string $email): void
    {
        // Le statut (utilisateur inconnu, envoi trop rapproché) est volontairement ignoré.
        Password::broker()->sendResetLink(['email' => $email]);
    }

    /**
     * @param  array{token: string, email: string, password: string}  $data
     * @return User le compte réinitialisé
     *
     * @throws ValidationException
     */
    public function reset(array $data): User
    {
        $resetUser = null;

        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password) use (&$resetUser): void {
                $resetUser = $user;

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return $resetUser;
    }
}
