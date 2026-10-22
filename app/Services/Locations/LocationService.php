<?php

namespace App\Services\Locations;

use App\Exceptions\GeocodingException;
use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Gère la création, la mise à jour et le géocodage des lieux via l'API OpenRouteService.
 *
 * `create()` : si les coordonnées sont déjà fournies (via autocomplétion), réutilise
 *   un emplacement existant avec le même place_id pour éviter les doublons.
 *   Sinon, géocode l'adresse via ORS.
 *
 * `suggest()` : autocomplétion via ORS /pelias/v1/autocomplete (boundary BE, max 6 résultats,
 *   adresses avec numéro uniquement, classées par proximité du point de référence de la commune).
 *   Localité absente chez ORS → déduite du code postal (BelgianPostalCodes) ; sans les deux, la suggestion est écartée.
 *   Numéro tapé inconnu d'OpenStreetMap (ORS ne renvoie alors rien) : second appel sur les rues, proposées avec
 *   le numéro tapé et `approximate: true` (coordonnées du milieu de la rue, lieu enregistré avec is_verified = false).
 *   Retourne un tableau vide si ORS n'est pas configuré ou en cas d'erreur HTTP.
 *
 * `geocode()` : résolution ORS /pelias/v1/search → lat/lng/place_id.
 *   Lève GeocodingException si ORS est inaccessible, ne retourne aucun résultat, ou ne trouve
 *   pas l'adresse exacte (repli sur la rue ou la ville : coordonnées trop imprécises pour un trajet).
 *
 * `calculateDistance()` : ORS /openrouteservice/v2/directions/driving-car → distance_km + estimated_duration_min.
 *   Lève GeocodingException si l'itinéraire est introuvable.
 *   Le PreviewService possède un fallback haversine × 1.25 si cette méthode échoue.
 *
 * Les URLs sont construites par `endpoint()` : ORS_BASE_URL + préfixe du service
 * (config services.openrouteservice.paths). La clé part toujours dans l'en-tête Authorization.
 */
class LocationService
{
    private ?string $apiKey;

    public function __construct(
        private readonly MunicipalityService $municipalityService,
        private readonly BelgianPostalCodes $postalCodes,
    ) {
        $this->apiKey = config('services.openrouteservice.key');
    }

    /**
     * URL complète d'un appel ORS, ex. endpoint('geocoding', '/search')
     * → https://api.heigit.org/pelias/v1/search.
     *
     * @throws \InvalidArgumentException si le service n'a pas de préfixe dans services.openrouteservice.paths
     */
    public static function endpoint(string $service, string $path): string
    {
        $prefix = config("services.openrouteservice.paths.{$service}");

        if (blank($prefix)) {
            throw new \InvalidArgumentException("Service ORS inconnu : {$service}");
        }

        $baseUrl = rtrim((string) config('services.openrouteservice.base_url'), '/');

        return $baseUrl.$prefix.$path;
    }

    public function create(array $data): Location
    {
        // If coordinates were already provided (e.g. from autocomplete), skip geocoding
        // and reuse an existing location with the same place_id if one exists.
        if (isset($data['latitude'], $data['longitude'])) {
            if (!empty($data['place_id'])) {
                $existing = Location::where('place_id', $data['place_id'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            // Suggestion « position approximative » (rue sans le numéro) : à vérifier, d'où is_verified = false
            $approximate = (bool) ($data['approximate'] ?? false);
            unset($data['approximate']);

            return Location::create([
                ...$data,
                'provider' => $data['provider'] ?? 'openrouteservice',
                'is_verified' => ! $approximate,
            ]);
        }

        $geocodedData = $this->geocode($data['formatted_address']);

        return Location::create([
            ...$data,
            ...$geocodedData,
        ]);
    }

    public function update(Location $location, array $data): Location
    {
        if (array_key_exists('formatted_address', $data)) {
            $data = [
                ...$data,
                ...$this->geocode($data['formatted_address']),
            ];
        }

        $location->update($data);

        return $location->refresh();
    }

    /**
     * Retourne des suggestions d'adresses via ORS /pelias/v1/autocomplete.
     * Retourne un tableau vide si ORS n'est pas configuré ou en cas d'erreur.
     *
     * @return array<int, array{label: string, formatted_address: string, street: ?string, street_number: ?string, postal_code: ?string, city: ?string, country: string, latitude: float, longitude: float, place_id: ?string}>
     */
    public function suggest(string $query): array
    {
        if (blank($this->apiKey) || blank(config('services.openrouteservice.base_url'))) {
            return [];
        }

        $response = Http::timeout(5)->withHeaders([
            'Authorization' => $this->apiKey,
        ])->get(self::endpoint('geocoding', '/autocomplete'), [
            'text' => $query,
            'boundary.country' => 'BE',
            'size' => 6,
            'layers' => 'address',
            ...$this->focusPoint(),
        ]);

        if ($response->failed()) {
            return [];
        }

        $suggestions = collect($response->json('features', []))
            ->map(fn (array $f): ?array => $this->suggestion($f))
            ->filter()
            ->values();

        $houseNumber = $this->typedHouseNumber($query, $response->json('geocoding.query.parsed_text.housenumber'));

        if ($houseNumber === null
            || $suggestions->contains(fn (array $s): bool => strcasecmp((string) $s['street_number'], $houseNumber) === 0)) {
            return $suggestions->all();
        }

        // Numéro inconnu : la rue avec ce numéro d'abord, puis les numéros connus de la rue
        return $this->approximateSuggestions($query, $houseNumber)
            ->concat($suggestions)
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * Numéro de maison tapé : celui qu'a découpé ORS, sinon le dernier nombre de 1 à 3 chiffres (« Place Reine Astrid 1 »,
     * qu'ORS lit comme une localité « Place »). Les codes postaux (4 chiffres) ne sont pas pris.
     */
    private function typedHouseNumber(string $query, ?string $parsed): ?string
    {
        if (filled($parsed)) {
            return trim($parsed);
        }

        return preg_match_all('/(?<![\d\/])\d{1,3}[a-zA-Z]?(?![\d\/])/', $query, $matches)
            ? end($matches[0])
            : null;
    }

    /**
     * Rues correspondant à la saisie sans le numéro, proposées avec le numéro tapé. Coordonnées : celles de la rue (ORS
     * n'a pas le numéro), signalées dans le libellé et par `approximate`. place_id propre au numéro, pour que deux numéros
     * de la même rue ne soient pas confondus par create().
     */
    private function approximateSuggestions(string $query, string $houseNumber): Collection
    {
        // Retire la dernière occurrence du numéro (celle que retient typedHouseNumber : « Rue du 11 Novembre 11 »)
        preg_match_all('/(?<![\d\/])'.preg_quote($houseNumber, '/').'(?![\d\/])/i', $query, $matches, PREG_OFFSET_CAPTURE);
        [$number, $offset] = end($matches[0]) ?: ['', 0];
        $street = substr_replace($query, '', $offset, strlen($number));
        $street = trim(preg_replace(['/\s+,/', '/\s{2,}/'], [',', ' '], $street), ' ,');

        $response = Http::timeout(5)->withHeaders([
            'Authorization' => $this->apiKey,
        ])->get(self::endpoint('geocoding', '/autocomplete'), [
            'text' => $street,
            'boundary.country' => 'BE',
            'size' => 3,
            'layers' => 'street',
            ...$this->focusPoint(),
        ]);

        if ($response->failed()) {
            return collect();
        }

        return collect($response->json('features', []))
            ->map(function (array $f) use ($houseNumber): ?array {
                $streetName = $f['properties']['name'] ?? $f['properties']['street'] ?? null;
                if (blank($streetName)) {
                    return null;
                }

                $suggestion = $this->suggestion([
                    ...$f,
                    'properties' => [
                        ...$f['properties'],
                        'name' => "{$streetName} {$houseNumber}",
                        'street' => $streetName,
                        'housenumber' => $houseNumber,
                    ],
                ]);

                return $suggestion ? [
                    ...$suggestion,
                    'label' => "{$suggestion['label']} (position approximative : milieu de la rue)",
                    'place_id' => filled($suggestion['place_id']) ? "{$suggestion['place_id']}#{$houseNumber}" : null,
                    'approximate' => true,
                ] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Suggestion prête à enregistrer, ou null si la localité est introuvable (ni ORS, ni code postal).
     * `county` n'est jamais utilisé comme ville : c'est l'arrondissement (« Dinant » couvre aussi Onhaye, Houyet…).
     * Libellé construit ici (« Rue Grande 1, 5500 Dinant ») : celui d'ORS omet souvent la localité (« Rue Grande 1, NA, Belgium »).
     */
    private function suggestion(array $feature): ?array
    {
        $properties = $feature['properties'] ?? [];
        [$longitude, $latitude] = $feature['geometry']['coordinates'];
        $postalCode = $properties['postalcode'] ?? null;

        $city = $properties['locality']
            ?? (filled($postalCode) ? $this->postalCodes->nearestLocality($postalCode, $latitude, $longitude) : null);
        $name = $properties['name']
            ?? trim(($properties['street'] ?? '').' '.($properties['housenumber'] ?? ''));

        if (blank($city) || blank($name)) {
            return null;
        }

        $label = $name.', '.trim(($postalCode ?? '').' '.$city);

        return [
            'label'             => $label,
            'formatted_address' => $label,
            'street'            => $properties['street'] ?? null,
            'street_number'     => $properties['housenumber'] ?? null,
            'postal_code'       => $postalCode,
            'city'              => $city,
            'country'           => 'Belgique',
            'latitude'          => $latitude,
            'longitude'         => $longitude,
            'place_id'          => $properties['id'] ?? null,
        ];
    }

    /**
     * Paramètres focus.point d'ORS : classe les résultats par proximité du dépôt ou du centre de la commune
     * (« Rue Grande 1 » à Dinant avant celle de Nismes). Vide si la commune n'a pas de point de référence.
     *
     * @return array<string, float>
     */
    private function focusPoint(): array
    {
        $reference = $this->municipalityService->referencePoint();

        return $reference
            ? ['focus.point.lat' => $reference['latitude'], 'focus.point.lon' => $reference['longitude']]
            : [];
    }

    /**
     * Géocode une adresse et retourne lat, lng, place_id
     *
     * @throws GeocodingException
     */
    public function geocode(string $address): array
    {
        $this->ensureConfigured();

        $response = Http::timeout(10)->withHeaders([
            'Authorization' => $this->apiKey,
        ])->get(self::endpoint('geocoding', '/search'), [
            'text' => $address,
            'boundary.country' => 'BE',
            'size' => 1,
            ...$this->focusPoint(),
        ]);

        if ($response->failed()) {
            throw new GeocodingException(
                "L'API OpenRouteService est inaccessible pour l'adresse : {$address}"
            );
        }

        $features = $response->json('features');

        if (empty($features)) {
            throw new GeocodingException(
                "Aucun résultat trouvé pour l'adresse : {$address}"
            );
        }

        $feature = $features[0];

        // ORS se replie sur la rue ou la ville quand il ne trouve pas l'adresse (layer street/locality,
        // match_type fallback) : ces coordonnées peuvent être à des centaines de mètres, on refuse.
        $layer = $feature['properties']['layer'] ?? null;

        if ($layer === 'street') {
            throw new GeocodingException(
                "Numéro introuvable : seule la rue est connue pour « {$address} ». Choisissez la suggestion « position approximative » ou un numéro proposé."
            );
        }

        if ($layer !== 'address' || ($feature['properties']['match_type'] ?? null) === 'fallback') {
            throw new GeocodingException(
                "Adresse introuvable : seule la commune est reconnue pour « {$address} ». Vérifiez la rue et le numéro, ajoutez le code postal, ou choisissez une suggestion."
            );
        }

        return [
            'latitude' => $feature['geometry']['coordinates'][1],
            'longitude' => $feature['geometry']['coordinates'][0],
            'place_id' => $feature['properties']['id'] ?? null,
            'provider' => 'openrouteservice',
        ];
    }

    /**
     * Calcule la distance routière entre deux points
     *
     * @throws GeocodingException
     */
    public function calculateDistance(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng
    ): array {
        $this->ensureConfigured();

        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post(self::endpoint('directions', '/driving-car'), [
                'coordinates' => [
                    [$fromLng, $fromLat],
                    [$toLng,   $toLat],
                ],
            ]);
        } catch (ConnectionException $exception) {
            // ORS injoignable ou trop lent : même traitement qu'une réponse en erreur
            // (les appelants basculent sur l'estimation à vol d'oiseau).
            throw new GeocodingException('Le service de calcul d’itinéraire est injoignable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new GeocodingException(
                'Impossible de calculer la distance entre les deux points.'
            );
        }

        $summary = $response->json('routes.0.summary');

        if (empty($summary)) {
            throw new GeocodingException(
                'Aucun itinéraire trouvé entre les deux points.'
            );
        }

        return [
            'distance_km' => round($summary['distance'] / 1000, 2),
            'estimated_duration_min' => round($summary['duration'] / 60),
        ];
    }

    /**
     * Tracé routier entre deux points (ORS, profil voiture, GeoJSON, instructions en français) :
     * géométrie [[lat, lng], …], distance (m), durée (s), étapes [{instruction, name, distance_m, duration_s}].
     *
     * @return array{geometry: list<array{0: float, 1: float}>, distance_m: int, duration_s: int, steps: list<array>}
     *
     * @throws GeocodingException
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $this->ensureConfigured();

        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post(self::endpoint('directions', '/driving-car/geojson'), [
                'coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]],
                'instructions' => true,
                'language' => 'fr',
                'units' => 'm',
            ]);
        } catch (ConnectionException $exception) {
            throw new GeocodingException('Le service de calcul d’itinéraire est injoignable.', previous: $exception);
        }

        $feature = $response->successful() ? $response->json('features.0') : null;

        if (empty($feature['geometry']['coordinates'])) {
            throw new GeocodingException('Aucun itinéraire trouvé entre les deux points.');
        }

        $summary = $feature['properties']['summary'] ?? [];
        $steps = collect($feature['properties']['segments'] ?? [])
            ->flatMap(fn (array $segment): array => $segment['steps'] ?? [])
            ->map(fn (array $step): array => [
                'instruction' => (string) ($step['instruction'] ?? ''),
                'name' => ($step['name'] ?? '') !== '-' ? (string) ($step['name'] ?? '') : '',
                'distance_m' => (int) round($step['distance'] ?? 0),
                'duration_s' => (int) round($step['duration'] ?? 0),
            ])
            ->values()
            ->all();

        return [
            // GeoJSON : [lng, lat] → [lat, lng] (ordre de Leaflet)
            'geometry' => array_map(fn (array $point): array => [round((float) $point[1], 6), round((float) $point[0], 6)], $feature['geometry']['coordinates']),
            'distance_m' => (int) round($summary['distance'] ?? 0),
            'duration_s' => (int) round($summary['duration'] ?? 0),
            'steps' => $steps,
        ];
    }

    /** @throws GeocodingException */
    private function ensureConfigured(): void
    {
        if (blank($this->apiKey) || blank(config('services.openrouteservice.base_url'))) {
            throw new GeocodingException('La configuration OpenRouteService est incomplete.');
        }
    }
}
