<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de GET /api/reservations/counts : plusieurs nombres de réservations en un seul appel
 * (pastilles d'une page, au lieu d'un GET /reservations par pastille).
 *
 * Filtres communs à tous les compteurs, comme GET /reservations : date_from, date_to, organization_id, q.
 * `counts[nom]` : un compteur, avec ses propres filtres (statuses[], council=a_decider, unassigned=1,
 * passengers_to_confirm=1, mêmes règles que la liste). Réponse : { data: { nom: nombre, … } }.
 * Exemple : counts[a-traiter][statuses][]=en_attente&counts[a-traiter][statuses][]=en_conflit&counts[confirmees][statuses][]=confirme
 */
class CountReservationsRequest extends FormRequest
{
    /** Au plus 12 compteurs par appel. */
    public const MAX_COUNTS = 12;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'counts' => [
                'required',
                'array',
                'min:1',
                'max:'.self::MAX_COUNTS,
                // Nom d'un compteur : lettres minuscules, chiffres, « - », « _ » et « : » (ex. a-traiter:en-attente)
                function (string $attribute, mixed $value, \Closure $fail): void {
                    foreach (array_keys($value) as $name) {
                        if (! preg_match('/^[a-z0-9:_-]{1,40}$/', (string) $name)) {
                            $fail('Nom de compteur invalide : lettres minuscules, chiffres, « - », « _ » ou « : ».');

                            return;
                        }
                    }
                },
            ],
            // Clés permises listées : sinon une clé inconnue (status au lieu de statuses[]) retirait le compteur des
            // données validées sans erreur (500 quand il était seul, compteur absent de la réponse sinon).
            'counts.*' => ['array:statuses,council,unassigned,passengers_to_confirm'],
            'counts.*.statuses' => ['sometimes', 'array', 'min:1'],
            'counts.*.statuses.*' => ['string', Rule::enum(ReservationStatus::class)],
            'counts.*.council' => ['sometimes', 'in:a_decider'],
            'counts.*.unassigned' => ['sometimes', 'boolean'],
            'counts.*.passengers_to_confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'counts.required' => 'Indiquez au moins un compteur (counts[nom]).',
            'counts.max' => 'Au plus '.self::MAX_COUNTS.' compteurs par appel.',
            'counts.*.array' => 'Filtre de compteur inconnu : statuses, council, unassigned ou passengers_to_confirm.',
            'counts.*.statuses.*.enum' => 'Statut inconnu.',
            'counts.*.council.in' => 'Le filtre council n’accepte que la valeur a_decider.',
        ];
    }
}
