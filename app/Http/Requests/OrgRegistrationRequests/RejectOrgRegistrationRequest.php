<?php

namespace App\Http\Requests\OrgRegistrationRequests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOrgRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('org-registration-requests.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
