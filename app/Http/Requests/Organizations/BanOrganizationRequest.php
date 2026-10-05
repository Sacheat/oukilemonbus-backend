<?php

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

class BanOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return is_object($organization)
            && ($this->user()?->can('ban', $organization) ?? false);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            // Annuler aussi ses réservations à venir (choix de l'admin, non par défaut)
            'cancel_upcoming' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif du bannissement.',
            'reason.max' => 'Le motif ne peut pas dépasser 500 caractères.',
        ];
    }
}