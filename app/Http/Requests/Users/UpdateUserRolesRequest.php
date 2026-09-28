<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return is_object($user)
            && ($this->user()?->can('updateRoles', $user) ?? false);
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(User::GLOBAL_ROLES)],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $target = $this->route('user');

                // Un admin ne peut pas se retirer lui-même le rôle admin (risque de ne plus avoir aucun admin).
                if (
                    $target instanceof User
                    && $target->is($this->user())
                    && $target->hasRole('admin')
                    && ! in_array('admin', (array) $this->input('roles', []), true)
                ) {
                    $validator->errors()->add('roles', 'Vous ne pouvez pas retirer votre propre rôle administrateur.');
                }
            },
        ];
    }
}
