<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'licence_number'      => [
                'sometimes',
                'string',
                'max:20',
                'unique:drivers,licence_number,' . $this->route('driver')?->id,
            ],
            'licence_expiry_date' => ['sometimes', 'date', 'after:today'],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_number.unique'        => "Ce numéro de permis existe déjà.",
            'licence_expiry_date.after'    => "La date d'expiration doit être dans le futur.",
        ];
    }
}
