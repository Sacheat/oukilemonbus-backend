<?php

namespace App\Http\Requests\OrgRegistrationRequests;

use App\Enums\OrganizationType;
use App\Models\OrgRegistrationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrgRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrgRegistrationRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'enterprise_number' => ['nullable', 'string', 'max:50'],
            'official_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'contact_person_name' => ['nullable', 'string', 'max:100'],
            'contact_person_role' => ['nullable', 'string', 'max:100'],
            'proof_document_path' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string'],
        ];
    }
}
