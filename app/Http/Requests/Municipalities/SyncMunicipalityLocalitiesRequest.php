<?php

namespace App\Http\Requests\Municipalities;

use Illuminate\Foundation\Http\FormRequest;

class SyncMunicipalityLocalitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
