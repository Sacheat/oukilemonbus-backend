<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de GET /api/reservations.
 * Les filtres historiques (status, organization_id, date_from, date_to) gardent leur comportement
 * tolérant. `statuses[]` filtre sur plusieurs statuts et ne se combine pas avec `status` (422).
 * `q` : recherche libre (voir ReservationController::index). L'accès est contrôlé par la Policy dans le contrôleur.
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
            'statuses' => ['sometimes', 'array', 'min:1', 'prohibits:status'],
            'statuses.*' => ['string', Rule::enum(ReservationStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'sort.in' => 'L’ordre de tri doit valoir asc ou desc.',
            'statuses.prohibits' => 'Utilisez soit status, soit statuses[], pas les deux.',
            'statuses.*.enum' => 'Statut inconnu.',
        ];
    }

    /** Ordre chronologique des trajets : desc (plus lointain d'abord) par défaut. */
    public function sortDirection(): string
    {
        return $this->validated('sort', 'desc');
    }
}
