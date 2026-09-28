<?php

namespace App\Http\Requests\Organizations;

use App\Models\UserOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return is_object($organization)
            && ($this->user()?->can('manageMembers', $organization) ?? false);
    }

    public function rules(): array
    {
        return [
            'relationship_type' => ['required', 'string', Rule::in(UserOrganization::RELATIONSHIP_TYPES)],
        ];
    }
}
