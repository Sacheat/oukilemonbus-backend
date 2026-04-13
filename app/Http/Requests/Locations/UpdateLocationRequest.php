<?php

namespace App\Http\Requests\Locations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('locations.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'formatted_address' => ['sometimes', 'required', 'string', 'max:500'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_verified' => ['sometimes', 'boolean'],
        ];
    }
}
