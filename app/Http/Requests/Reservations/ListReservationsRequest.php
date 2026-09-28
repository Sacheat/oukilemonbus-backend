<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paramètres de GET /api/reservations.
 * Seul `sort` est validé : les filtres existants (status, organization_id, date_from, date_to)
 * gardent leur comportement tolérant. L'accès est contrôlé par la Policy dans le contrôleur.
 */
class ListReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ['sometimes', 'in:asc,desc'],
        ];
    }

    public function messages(): array
    {
        return [
            'sort.in' => 'L’ordre de tri doit valoir asc ou desc.',
        ];
    }

    /** Ordre chronologique des trajets : desc (plus lointain d'abord) par défaut. */
    public function sortDirection(): string
    {
        return $this->validated('sort', 'desc');
    }
}
