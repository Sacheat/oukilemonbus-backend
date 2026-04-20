<?php

namespace App\Http\Requests\UserOrgRequests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveUserOrgRequest extends FormRequest
{
    public function authorize(): bool
    {
        $userOrgRequest = $this->route('userOrgRequest');

        return is_object($userOrgRequest)
            && ($this->user()?->can('approve', $userOrgRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'confidence_note' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
