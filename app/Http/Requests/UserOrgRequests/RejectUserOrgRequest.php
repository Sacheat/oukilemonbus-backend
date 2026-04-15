<?php

namespace App\Http\Requests\UserOrgRequests;

use Illuminate\Foundation\Http\FormRequest;

class RejectUserOrgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user-org-requests.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'confidence_note' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
