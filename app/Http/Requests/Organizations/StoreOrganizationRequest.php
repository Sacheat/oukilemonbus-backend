<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('organizations.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'official_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone_contact' => ['nullable', 'string', 'max:20'],
            'name_contact' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'is_priority' => ['sometimes', 'boolean'],
            'annual_quota' => ['sometimes', 'integer', 'min:0'],
            'document_proof_path' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => "Le nom de l'organisation est obligatoire.",
            'type.required' => "Le type d'organisation est obligatoire.",
            'type' => "Le type d'organisation est invalide.",
        ];
    }
}
