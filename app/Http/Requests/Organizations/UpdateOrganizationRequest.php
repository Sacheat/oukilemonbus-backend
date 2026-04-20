<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return is_object($organization)
            && ($this->user()?->can('update', $organization) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'type' => ['sometimes', 'required', Rule::enum(OrganizationType::class)],
            'official_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone_contact' => ['sometimes', 'nullable', 'string', 'max:20'],
            'name_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'is_priority' => ['sometimes', 'boolean'],
            'annual_quota' => ['sometimes', 'integer', 'min:0'],
            'document_proof_path' => ['sometimes', 'nullable', 'string', 'max:500'],
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
