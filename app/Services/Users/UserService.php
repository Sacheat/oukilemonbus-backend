<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Arr;

class UserService
{
    public function create(array $data): User
    {
        return User::create(Arr::only($data, [
            'first_name',
            'last_name',
            'email',
            'password',
            'phone',
            'active',
        ]));
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
