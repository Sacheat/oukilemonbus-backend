<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'licence_plate' => [
                'sometimes',
                'string',
                'max:20',
                'unique:vehicles,licence_plate,' . $this->route('vehicle')?->id,
            ],
            'capacity'  => ['sometimes', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_plate.unique' => "Cette plaque d'immatriculation est déjà utilisée.",
            'capacity.min'         => "La capacité doit être au moins 1.",
        ];
    }
}
