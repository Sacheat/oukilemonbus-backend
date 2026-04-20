<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return is_object($user)
            && ($this->user()?->can('updateStatus', $user) ?? false);
    }

    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
