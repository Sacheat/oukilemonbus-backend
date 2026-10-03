<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RequestStatus;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Paramètres communs aux files de demandes traitées par les agents (adhésions et nouvelles organisations) :
 *   - `statuses[]` : en_attente, approuve, refuse (plusieurs possibles) ;
 *   - `q` : recherche libre, chaque mot doit apparaître quelque part (casse et accents ignorés) ;
 *   - `sort` : confiance (défaut), anciennete (plus anciennes d'abord : ordre d'arrivée), recentes ;
 *   - `per_page` : 1 à 100, 15 par défaut (`?statuses[]=en_attente&per_page=1` donne le compteur dans meta.total).
 * La classe qui l'utilise ajoute son filtre propre (organization_id, organization_type) et sa recherche.
 */
trait FiltersReviewQueue
{
    public const array SORTS = ['confiance', 'anciennete', 'recentes'];

    /** @return array<string, mixed> */
    protected function queueRules(): array
    {
        return [
            'statuses' => ['sometimes', 'array', 'min:1'],
            'statuses.*' => ['string', Rule::enum(RequestStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    protected function queueMessages(): array
    {
        return [
            'statuses.array' => 'Les statuts doivent être envoyés sous la forme statuses[]=en_attente.',
            'statuses.*.enum' => 'Statut inconnu : utilisez en_attente, approuve ou refuse.',
            'sort.in' => 'Le tri doit valoir confiance, anciennete ou recentes.',
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 15);
    }

    /** Filtre de statut puis tri. $primaryKey départage deux demandes créées à la même seconde (pagination stable). */
    public function applyStatusAndSort(Builder $query, string $primaryKey): Builder
    {
        $statuses = $this->validated('statuses');

        $query->when($statuses, fn (Builder $q) => $q->whereIn('status', $statuses));

        match ($this->validated('sort', 'confiance')) {
            'anciennete' => $query->orderBy('created_at')->orderBy($primaryKey),
            'recentes' => $query->orderByDesc('created_at')->orderByDesc($primaryKey),
            default => $query->orderByDesc('confidence_note')->orderByDesc('created_at')->orderByDesc($primaryKey),
        };

        return $query;
    }

    /**
     * Chaque mot de `q` doit apparaître dans au moins une des colonnes de la demande ou du demandeur
     * (prénom, nom, email). Les jokers LIKE saisis sont échappés ; casse et accents sont ignorés par la collation.
     *
     * @param  list<string>  $columns  colonnes de la demande
     * @param  string  $requesterRelation  relation vers le demandeur
     * @param  array<string, string>  $relationColumns  autres relations => colonne, ex. ['organization' => 'name']
     */
    public function applySearch(Builder $query, array $columns, string $requesterRelation, array $relationColumns = []): Builder
    {
        foreach (LikeSearch::patterns($this->validated('q')) as $like) {
            $query->where(function (Builder $word) use ($like, $columns, $requesterRelation, $relationColumns): void {
                foreach ($columns as $column) {
                    $word->orWhere($column, 'like', $like);
                }

                foreach ($relationColumns as $relation => $column) {
                    $word->orWhereHas($relation, fn (Builder $q) => $q->where($column, 'like', $like));
                }

                $word->orWhereHas($requesterRelation, fn (Builder $q) => $q
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            });
        }

        return $query;
    }
}
