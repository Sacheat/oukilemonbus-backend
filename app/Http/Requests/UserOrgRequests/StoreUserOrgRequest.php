<?php

namespace App\Http\Requests\UserOrgRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserOrgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user-org-requests.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'requested_role' => ['required', 'string', 'max:50', Rule::in([
                'prof',
                'directeur',
                'animateur',
                'responsable',
                'referent',
            ])],
            'justification' => ['nullable', 'string'],
            'proof_document_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
