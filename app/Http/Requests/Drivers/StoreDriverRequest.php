<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('drivers.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:drivers,user_id'],
            'licence_number' => ['required', 'string', 'max:20', 'unique:drivers,licence_number'],
            'licence_expiry_date' => ['required', 'date', 'after:today'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => "L'utilisateur est obligatoire.",
            'user_id.exists' => "Cet utilisateur n'existe pas.",
            'user_id.unique' => 'Cet utilisateur est déjà chauffeur.',
            'licence_number.required' => 'Le numéro de permis est obligatoire.',
            'licence_number.unique' => 'Ce numéro de permis existe déjà.',
            'licence_expiry_date.required' => "La date d'expiration du permis est obligatoire.",
            'licence_expiry_date.after' => "La date d'expiration doit être dans le futur.",
        ];
    }
}
