<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Arr;

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

    public function deactivate(User $user): User
    {
        $user->tokens()->delete();

        return $this->updateStatus($user, false);
    }
}
