<?php

namespace App\Http\Requests\Municipalities;

use Illuminate\Foundation\Http\FormRequest;

class StoreMunicipalityLocalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'best_id' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
