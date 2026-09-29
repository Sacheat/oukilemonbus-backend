<?php

namespace App\Http\Requests\Municipalities;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nis_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'size:2'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'depot_location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id', $this->geolocatedDepot(...)],
            'max_trip_distance_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'max_pickup_distance_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * Le dépôt sert de point de référence pour la distance jusqu'au lieu de départ :
     * il doit donc avoir des coordonnées.
     */
    private function geolocatedDepot(string $attribute, mixed $value, \Closure $fail): void
    {
        $location = Location::find($value);

        if ($location && (blank($location->latitude) || blank($location->longitude))) {
            $fail('Le lieu choisi comme dépôt doit être géolocalisé (latitude et longitude).');
        }
    }
}
