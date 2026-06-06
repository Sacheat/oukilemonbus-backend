<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use Illuminate\Support\Collection;

/**
 * Résout les localités d'une commune depuis l'API GeoNames avec une stratégie multi-niveaux.
 *
 * `findMunicipality()` : recherche l'entité GeoNames avec priorité décroissante
 *   ADM4 (commune) → featureClass A (entités administratives) → featureClass P (lieux peuplés).
 *   Évite de confondre une commune avec un arrondissement homonyme (ex. "Dinant" ADM3 vs ADM4).
 *
 * `fetchLocalities()` : détermine les localités selon le niveau de l'entité trouvée :
 *   - Pays/ADM1/ADM2/ADM3 → toutes les communes ADM4 de la zone (pagineé, limite 10 000)
 *   - ADM4 → sous-localités via childrenJSON (fallback : l'entité elle-même)
 *   - PPL/autre → l'entité elle-même comme unique localité
 */
class GeoNamesMunicipalityProvider
{
    // Feature codes pour les entités de niveau pays
    private const COUNTRY_FCODES = ['PCLI', 'PCLH', 'PCLIX', 'PCL'];

    public function __construct(
        private readonly GeoNamesClient $client
    ) {}

    /**
     * Trouve l'entité GeoNames correspondant au nom saisi.
     * Retourne le résultat complet (style=FULL) pour disposer des adminCodes.
     *
     * Stratégie de recherche par priorité décroissante :
     *   1. featureCode=ADM4  (commune)   — évite de confondre une commune avec un
     *      arrondissement homonyme (ex. "Dinant" retourne ADM3 avant ADM4 sans ce filtre)
     *   2. featureClass=A    (toutes les entités administratives : pays, régions, provinces…)
     *   3. featureClass=P    (lieux peuplés, dernier recours)
     */
    public function findMunicipality(Municipality $municipality): ?array
    {
        $country = $municipality->country ?: 'BE';
        $base    = ['name_equals' => $municipality->name, 'country' => $country, 'maxRows' => 1, 'style' => 'FULL', 'lang' => 'fr'];

        $attempts = [
            ['featureCode' => 'ADM4'],
            ['featureClass' => 'A'],
            ['featureClass' => 'P'],
        ];

        foreach ($attempts as $params) {
            $items = $this->client->get('searchJSON', array_merge($base, $params))['geonames'] ?? [];

            if (! empty($items)) {
                return $items[0];
            }
        }

        return null;
    }

    /**
     * Retourne la liste des localités à ajouter à la whitelist.
     *
     * Le comportement dépend du niveau de l'entité trouvée :
     *   - Pays  (PCLI …)  → toutes les communes ADM4 du pays
     *   - ADM1  (région)  → toutes les communes ADM4 de la région
     *   - ADM2  (province)→ toutes les communes ADM4 de la province
     *   - ADM3  (district)→ toutes les communes ADM4 du district
     *   - ADM4  (commune) → sections / lieux peuplés via childrenJSON
     *   - Autre / PPL     → l'entité elle-même comme seule localité
     *
     * L'entité GeoNames peut être fournie directement (évite un appel API
     * supplémentaire quand syncFromExternalProvider l'a déjà récupérée).
     */
    public function fetchLocalities(Municipality $municipality, ?array $entity = null): Collection
    {
        $entity ??= $this->findMunicipality($municipality);

        if ($entity === null) {
            return collect();
        }

        return $this->localitiesForEntity($entity, $municipality->country ?: 'BE');
    }

    // -------------------------------------------------------------------------
    // Private — routing par niveau
    // -------------------------------------------------------------------------

    private function localitiesForEntity(array $entity, string $country): Collection
    {
        $fcode     = $entity['fcode'] ?? '';
        $geonameId = (int) ($entity['geonameId'] ?? 0);

        if (in_array($fcode, self::COUNTRY_FCODES, true)) {
            return $this->searchCommunes($country, []);
        }

        if ($fcode === 'ADM1') {
            return $this->searchCommunes($country, [
                'adminCode1' => $entity['adminCode1'] ?? null,
            ]);
        }

        if ($fcode === 'ADM2') {
            return $this->searchCommunes($country, [
                'adminCode1' => $entity['adminCode1'] ?? null,
                'adminCode2' => $entity['adminCode2'] ?? null,
            ]);
        }

        if ($fcode === 'ADM3') {
            return $this->searchCommunes($country, [
                'adminCode1' => $entity['adminCode1'] ?? null,
                'adminCode2' => $entity['adminCode2'] ?? null,
                'adminCode3' => $entity['adminCode3'] ?? null,
            ]);
        }

        if ($fcode === 'ADM4') {
            return $this->fetchSubLocalities($geonameId, $entity);
        }

        // PPL, PPLX ou autre — l'entité elle-même comme seule localité
        return collect([[
            'id'   => $geonameId ?: null,
            'name' => $entity['name'],
        ]]);
    }

    // -------------------------------------------------------------------------
    // Private — récupération des communes (tous niveaux sauf ADM4)
    // -------------------------------------------------------------------------

    /**
     * Récupère toutes les communes (featureCode=ADM4) dans la zone délimitée par
     * les adminCodes. Pagine automatiquement jusqu'à ce que GeoNames ne retourne
     * plus de résultats (ou jusqu'à la limite de sécurité de 10 000 entrées).
     *
     * @param  array<string,string|null>  $adminCodes
     */
    private function searchCommunes(string $country, array $adminCodes): Collection
    {
        $communes  = collect();
        $batchSize = min((int) config('services.geonames.max_rows', 1000), 1000);
        $startRow  = 0;
        $safetyMax = 10_000;

        do {
            $params = array_filter([
                'country'     => $country,
                'featureCode' => 'ADM4',
                'maxRows'     => $batchSize,
                'startRow'    => $startRow,
                'style'       => 'SHORT',
                'lang'        => 'fr',
                ...$adminCodes,
            ]);

            $items = $this->client->get('searchJSON', $params)['geonames'] ?? [];

            foreach ($items as $item) {
                $locality = $this->extractFromResult($item);
                if ($locality !== null) {
                    $communes->push($locality);
                }
            }

            $startRow += count($items);
        } while (count($items) === $batchSize && $startRow < $safetyMax);

        return $communes->values();
    }

    // -------------------------------------------------------------------------
    // Private — sous-localités d'une commune (ADM4)
    // -------------------------------------------------------------------------

    /**
     * Pour une commune (ADM4) : récupère ses enfants via childrenJSON.
     * Si GeoNames n'en retourne aucun, la commune elle-même est la seule localité.
     */
    private function fetchSubLocalities(int $geonameId, array $entity): Collection
    {
        $children = $this->fetchChildren($geonameId);

        if ($children->isNotEmpty()) {
            return $children;
        }

        return collect([[
            'id'   => $geonameId,
            'name' => $entity['name'],
        ]]);
    }

    /**
     * Appelle childrenJSON et retourne les enfants directs de l'entité.
     */
    private function fetchChildren(int $geonameId): Collection
    {
        $items = $this->client->get('childrenJSON', [
            'geonameId' => $geonameId,
            'maxRows'   => (int) config('services.geonames.max_rows', 1000),
            'style'     => 'FULL',
            'lang'      => 'fr',
        ])['geonames'] ?? [];

        return collect($items)
            ->map(fn (array $item): ?array => $this->extractFromResult($item))
            ->filter()
            ->values();
    }

    // -------------------------------------------------------------------------
    // Private — extraction
    // -------------------------------------------------------------------------

    private function extractFromResult(array $item): ?array
    {
        $name = $item['name'] ?? $item['toponymName'] ?? null;

        if (blank($name)) {
            return null;
        }

        return [
            'id'   => $item['geonameId'] ?? null,
            'name' => $name,
        ];
    }
}