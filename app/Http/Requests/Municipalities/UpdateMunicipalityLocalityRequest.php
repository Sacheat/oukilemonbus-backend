<?php

namespace App\Http\Requests\Municipalities;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMunicipalityLocalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'best_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
