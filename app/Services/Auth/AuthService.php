<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Gère l'inscription, la connexion et la déconnexion via la garde Sanctum SPA (guard='web').
 *
 * register() : crée l'utilisateur, assigne le rôle 'user', envoie la vérification email,
 *              puis connecte immédiatement la session.
 * login()    : vérifie le mot de passe ET que le compte est actif (active = true).
 *              Un compte inactif reçoit le même message d'erreur qu'un mot de passe incorrect
 *              pour ne pas révéler l'existence du compte.
 * logout()   : invalide la session web et purge toutes les gardes.
 */
class AuthService
{
    public function register(array $data): User
    {
        $user = User::create(Arr::only($data, [
            'first_name',
            'last_name',
            'email',
            'password',
            'phone',
        ]));

        $user->forceFill([
            'privacy_policy_version' => config('privacy.policy.version'),
            'privacy_policy_accepted_at' => now(),
        ])->save();

        $user->assignRole(Role::findOrCreate('user', 'web'));

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function login(array $credentials, bool $remember = false): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->active) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        Auth::login($user, $remember);

        return $user;
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();
    }
}
