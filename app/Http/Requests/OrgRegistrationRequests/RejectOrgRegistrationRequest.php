<?php

namespace App\Http\Requests\OrgRegistrationRequests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOrgRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orgRegistrationRequest = $this->route('orgRegistrationRequest');

        return is_object($orgRegistrationRequest)
            && ($this->user()?->can('reject', $orgRegistrationRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
