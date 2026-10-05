<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de GET /api/organizations (combinables, conservés dans les liens de pagination) :
 *   q        chaque mot dans le nom, l'email officiel ou le contact (casse et accents ignorés)
 *   type     ecole_communale, personnel_communal, cpas, asbl, externe
 *   banned   0 ou 1
 *   sort     nom (défaut), quota (les plus proches de leur limite d'abord), activite (le plus de réservations à venir)
 *   per_page 1 à 100 (15 par défaut)
 */
class ListOrganizationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(OrganizationType::class)],
            'banned' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:nom,quota,activite'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'Type d’organisation inconnu.',
            'banned.boolean' => 'Le filtre « bannies » doit valoir 0 ou 1.',
            'sort.in' => 'Tri inconnu : nom, quota ou activite.',
            'per_page.max' => 'Au plus 100 organisations par page.',
        ];
    }
}
