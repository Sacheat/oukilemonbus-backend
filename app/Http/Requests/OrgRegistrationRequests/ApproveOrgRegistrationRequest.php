<?php

namespace App\Http\Requests\OrgRegistrationRequests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveOrgRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orgRegistrationRequest = $this->route('orgRegistrationRequest');

        return is_object($orgRegistrationRequest)
            && ($this->user()?->can('approve', $orgRegistrationRequest) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
