<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de GET /api/reservations.
 * Les filtres historiques (status, organization_id, date_from, date_to) gardent leur comportement
 * tolérant. `statuses[]` filtre sur plusieurs statuts et ne se combine pas avec `status` (422).
 * `q` : recherche libre (voir ReservationController::index).
 * `council=a_decider` : réservations qui attendent le Collège (scope Reservation::awaitingCouncilDecision,
 * même règle que le compteur council_pending du tableau de bord).
 * `unassigned=1` : réservations auxquelles il manque un chauffeur ou un véhicule (file « À affecter » de la gestion,
 * avec statuses[]=approuve) ; filtré par la base, pour garder une pagination juste.
 * `passengers_to_confirm=1` : trajets actifs des 14 prochains jours dont les passagers ne sont pas confirmés
 * (scope Reservation::awaitingPassengerConfirmation, même règle que le compteur du tableau de bord).
 * L'accès est contrôlé par la Policy dans le contrôleur.
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
            'council' => ['sometimes', 'in:a_decider'],
            'unassigned' => ['sometimes', 'boolean'],
            'passengers_to_confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sort.in' => 'L’ordre de tri doit valoir asc ou desc.',
            'statuses.prohibits' => 'Utilisez soit status, soit statuses[], pas les deux.',
            'statuses.*.enum' => 'Statut inconnu.',
            'council.in' => 'Le filtre council n’accepte que la valeur a_decider.',
            'passengers_to_confirm.boolean' => 'Le filtre passengers_to_confirm doit valoir 0 ou 1.',
        ];
    }

    /** Ordre chronologique des trajets : desc (plus lointain d'abord) par défaut. */
    public function sortDirection(): string
    {
        return $this->validated('sort', 'desc');
    }
}
