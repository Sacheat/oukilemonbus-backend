<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organization::class) ?? false;
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
            // 0 = aucune réservation possible ; défaut config('reservations.annual_quota')
            'annual_quota' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            // Justificatif : un document existant (celui de la demande de création, par exemple)
            'proof_document_id' => ['nullable', 'integer', 'exists:documents,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => "Le nom de l'organisation est obligatoire.",
            'type.required' => "Le type d'organisation est obligatoire.",
            'type' => "Le type d'organisation est invalide.",
            'name.max' => "Le nom de l'organisation ne peut pas dépasser 50 caractères.",
            'official_email.email' => "L'email officiel n'est pas valide.",
            'phone_contact.max' => 'Le téléphone ne peut pas dépasser 20 caractères.',
            'location_id.exists' => 'Ce lieu n’existe pas.',
            'annual_quota.min' => 'Le quota annuel ne peut pas être négatif.',
            'annual_quota.integer' => 'Le quota annuel doit être un nombre entier.',
            'proof_document_id.exists' => 'Ce justificatif n’existe pas.',
        ];
    }
}
