<?php

namespace App\Http\Requests\UserOrgRequests;

use App\Http\Requests\Concerns\FiltersReviewQueue;
use App\Models\UserOrgRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paramètres de GET /api/user-org-requests : statuses[], q (organisation ; prénom, nom, email du demandeur),
 * organization_id, sort, per_page. Le périmètre visible est appliqué par le contrôleur.
 */
class ListUserOrgRequestsRequest extends FormRequest
{
    use FiltersReviewQueue;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', UserOrgRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...$this->queueRules(),
            'organization_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return $this->queueMessages();
    }
}
