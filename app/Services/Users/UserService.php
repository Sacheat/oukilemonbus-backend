<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserService
{
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

        return $user->load('roles');
    }

    /**
     * Remplace les rôles globaux de l'utilisateur.
     *
     * @param  list<string>  $roles
     */
    public function updateRoles(User $user, array $roles): User
    {
        $user->syncRoles($roles);

        return $user->load('roles');
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
        $user->tokens()->delete();

        return $this->updateStatus($user, false);
    }
}
