<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'licence_plate' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('vehicles', 'licence_plate')->ignore($this->route('vehicle')),
            ],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_plate.unique' => "Cette plaque d'immatriculation est deja utilisee.",
            'capacity.min' => 'La capacite doit etre au moins 1.',
        ];
    }
}
