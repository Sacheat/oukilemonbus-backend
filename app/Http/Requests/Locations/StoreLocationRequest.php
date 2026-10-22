<?php

namespace App\Http\Requests\Locations;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('locations.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'formatted_address' => ['required', 'string', 'max:500'],
            'street'            => ['nullable', 'string', 'max:255'],
            'street_number'     => ['nullable', 'string', 'max:20'],
            'postal_code'       => ['nullable', 'string', 'max:20'],
            'city'              => ['required', 'string', 'max:100'],
            'country'           => ['nullable', 'string', 'max:100'],
            // Pre-geocoded coordinates from ORS autocomplete — skip geocoding if present
            'latitude'          => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'         => ['nullable', 'numeric', 'between:-180,180'],
            'place_id'          => ['nullable', 'string', 'max:500'],
            // Suggestion « position approximative » (numéro inconnu, coordonnées de la rue) → is_verified = false
            'approximate'       => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'formatted_address.required' => "L'adresse complete est obligatoire.",
            'city.required' => 'La ville est obligatoire.',
        ];
    }
}
