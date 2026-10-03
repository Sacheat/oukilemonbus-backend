<?php

namespace App\Http\Requests\OrgRegistrationRequests;

use App\Enums\OrganizationType;
use App\Http\Requests\Concerns\FiltersReviewQueue;
use App\Models\OrgRegistrationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de GET /api/org-registration-requests : statuses[], q (nom, email officiel, numéro d'entreprise ;
 * prénom, nom, email du demandeur), organization_type, sort, per_page. Le périmètre visible est appliqué par le contrôleur.
 */
class ListOrgRegistrationRequestsRequest extends FormRequest
{
    use FiltersReviewQueue;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', OrgRegistrationRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...$this->queueRules(),
            'organization_type' => ['sometimes', Rule::enum(OrganizationType::class)],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->queueMessages(),
            'organization_type.enum' => 'Type d’organisation inconnu.',
        ];
    }
}
