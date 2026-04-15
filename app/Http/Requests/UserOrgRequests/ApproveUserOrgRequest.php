<?php

namespace App\Http\Requests\UserOrgRequests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveUserOrgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user-org-requests.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'confidence_note' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
