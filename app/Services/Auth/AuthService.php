<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

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
