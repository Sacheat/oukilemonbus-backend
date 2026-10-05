<?php

namespace App\Services\Users;

use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class UserService
{
    private const string LAST_ADMIN_DEACTIVATION = 'Impossible de désactiver le dernier administrateur actif.';

    public function create(array $data): User
    {
        $user = User::create(Arr::only($data, [
            'first_name',
            'last_name',
            'email',
            'password',
            'phone',
            'active',
        ]));

        // Même rôle de départ qu'à l'inscription ; l'admin l'ajuste ensuite via updateRoles().
        $user->assignRole('user');

        // Même email qu'à l'inscription (en file d'attente) : une panne d'envoi ne fait pas échouer la création.
        if (! $user->hasVerifiedEmail()) {
            try {
                // Même email que l'inscription, avec un accueil adapté : le compte a été créé par un admin.
                $user->notify(new QueuedVerifyEmail(createdByAdmin: true));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $user->load('roles');
    }

    /**
     * Envoie au compte le même lien que « Mot de passe oublié » (broker Laravel, jeton de 60 min).
     * L'admin ne fixe aucun mot de passe et ne voit jamais le jeton.
     *
     * @throws ValidationException compte désactivé, ou lien déjà envoyé il y a moins de auth.passwords.users.throttle secondes
     */
    public function sendPasswordResetLink(User $user): void
    {
        if (! $user->active) {
            throw ValidationException::withMessages([
                'user' => "Ce compte est désactivé : réactivez-le avant d'envoyer un lien.",
            ]);
        }

        // Callback du broker : même lien que « Mot de passe oublié », avec l'introduction « Un administrateur vous a envoyé… ».
        $status = Password::broker()->sendResetLink(['email' => $user->email], function (User $target, string $token): void {
            $target->notify(new QueuedResetPassword($token, sentByAdmin: true));
            event(new PasswordResetLinkSent($target));
        });

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'user' => "Un lien de réinitialisation vient déjà d'être envoyé à ce compte. Réessayez dans une minute.",
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['user' => __($status)]);
        }
    }

    /**
     * Remplace les rôles globaux de l'utilisateur.
     *
     * @param  list<string>  $roles
     */
    public function updateRoles(User $user, array $roles): User
    {
        return DB::transaction(function () use ($user, $roles): User {
            if (! in_array('admin', $roles, true)) {
                $this->ensureNotLastActiveAdmin(
                    $user,
                    'roles',
                    'Impossible de retirer le rôle administrateur du dernier administrateur actif.'
                );
            }

            $user->syncRoles($roles);

            return $user->load('roles');
        });
    }

    public function update(User $user, array $data): User
    {
        $user->update(Arr::only($data, [
            'first_name',
            'last_name',
            'email',
            'phone',
        ]));

        return $user->refresh();
    }

    public function updateStatus(User $user, bool $active): User
    {
        return DB::transaction(function () use ($user, $active): User {
            if (! $active) {
                $this->ensureNotLastActiveAdmin($user, 'active', self::LAST_ADMIN_DEACTIVATION);
            }

            return $this->writeStatus($user, $active);
        });
    }

    private function writeStatus(User $user, bool $active): User
    {
        $user->update([
            'active' => $active,
        ]);

        return $user->refresh();
    }

    public function updatePassword(User $user, string $password): User
    {
        $user->update([
            'password' => $password,
        ]);

        return $user->refresh();
    }

    /**
     * Ferme toutes les sessions de l'utilisateur sauf $keepSessionId (session en base) et révoque
     * ses tokens API. Sans session en base, les autres sessions restent de toute façon rejetées
     * par AuthenticateSession, dont le hash de mot de passe ne correspond plus.
     */
    public function closeOtherSessions(User $user, ?string $keepSessionId = null): void
    {
        $user->tokens()->delete();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keepSessionId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->delete();
    }

    public function deactivate(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $this->ensureNotLastActiveAdmin($user, 'user', self::LAST_ADMIN_DEACTIVATION);

            $user->tokens()->delete();

            return $this->writeStatus($user, false);
        });
    }

    /**
     * La plateforme garde toujours au moins un administrateur actif (rôle `admin` et `active = true`).
     * Les lignes des admins actifs sont verrouillées : deux requêtes simultanées qui retirent chacune
     * un admin différent ne peuvent pas passer toutes les deux.
     */
    private function ensureNotLastActiveAdmin(User $user, string $field, string $message): void
    {
        $activeAdminIds = User::query()
            ->role('admin')
            ->where('active', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($activeAdminIds->contains($user->getKey()) && $activeAdminIds->count() === 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
