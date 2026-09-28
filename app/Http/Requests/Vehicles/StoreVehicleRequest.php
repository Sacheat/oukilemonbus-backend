<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'licence_plate' => ['required', 'string', 'max:20', 'unique:vehicles,licence_plate'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_plate.required' => "La plaque d'immatriculation est obligatoire.",
            'licence_plate.unique' => 'Ce véhicule existe déjà.',
            'capacity.required' => 'La capacité est obligatoire.',
            'capacity.min' => 'La capacité doit être au moins 1.',
        ];
    }
}
