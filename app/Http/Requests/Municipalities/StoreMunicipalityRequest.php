<?php

namespace App\Http\Requests\Municipalities;

use Illuminate\Foundation\Http\FormRequest;

class StoreMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:500'],
            'nis_code' => ['nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'localities' => ['sometimes', 'array'],
            'localities.*' => ['required', 'string', 'max:100'],
        ];
    }
}
