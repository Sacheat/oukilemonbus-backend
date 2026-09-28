<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('drivers.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'licence_number' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('drivers', 'licence_number')->ignore($this->route('driver')),
            ],
            'licence_expiry_date' => ['sometimes', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_number.unique' => 'Ce numéro de permis existe déjà.',
            'licence_expiry_date.after' => "La date d'expiration doit être dans le futur.",
        ];
    }
}
